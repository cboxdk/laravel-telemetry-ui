<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Analysis\Snapshot;

use Cbox\TelemetryUi\Analysis\TraceDiagnosis;
use Cbox\TelemetryUi\Explore\TraceExceptions;
use Cbox\TelemetryUi\Queries\Results\Span;
use Cbox\TelemetryUi\Queries\Results\Trace;
use Cbox\TelemetryUi\Support\DashboardUrl;

/**
 * One trace as Markdown, to paste into an AI agent, a pull request or a
 * support ticket ("copy as Markdown"): who it was, what is wrong with it
 * (the {@see TraceDiagnosis} flags, errors first), where the time went, the
 * exceptions it threw with the app frames and source excerpt their records
 * carry, the spans that led up to the first failure, and the N+1 / duplicate /
 * slow query groups with the line that ran them.
 *
 * Built from what the trace store and the exception records already hold —
 * nothing is looked up or reconstructed: SQL is the statement text as the app
 * recorded it (laravel-telemetry records the parameterised statement; bindings
 * are never read or added), and a value the records do not carry is left out
 * rather than guessed. Every list has its own cap and says how many it left
 * out; the whole is capped by {@see MarkdownSnapshot::MAX_BYTES}.
 *
 * @phpstan-import-type DetailedTraceException from TraceExceptions
 */
final class TraceSnapshot
{
    public const LEAD_UP = 10;

    public const MAX_FLAGS = 20;

    public const MAX_EXCEPTIONS = 3;

    public const MAX_APP_FRAMES = 8;

    public const MAX_QUERY_GROUPS = 8;

    private const SQL_BYTES = 1_200;

    private const SOURCE_BYTES = 1_500;

    private const QUERY_KINDS = ['n_plus_one' => 'N+1', 'duplicate' => 'Duplicate', 'repeated' => 'Repeated', 'slow' => 'Slow query'];

    /**
     * @param  list<DetailedTraceException>  $exceptions
     */
    public static function build(Trace $trace, TraceDiagnosis $diagnosis, array $exceptions = [], int $maxBytes = MarkdownSnapshot::MAX_BYTES): MarkdownSnapshot
    {
        return MarkdownSnapshot::assemble([
            ['Summary', self::title($diagnosis)],
            ['Flags', self::flags($diagnosis)],
            ['Time by category', self::timing($diagnosis)],
            ['Totals', self::totals($diagnosis)],
            ['Exceptions', self::exceptions($exceptions)],
            ['What led up to it', self::leadUp($trace)],
            ['Query groups', self::queryGroups($diagnosis)],
        ], '_Snapshot from telemetry-ui, built from stored telemetry only: SQL is shown as the app recorded it, and query bindings are never added._', $maxBytes);
    }

    private static function title(TraceDiagnosis $d): string
    {
        $i = $d->identity;
        $name = self::str($i['name'] ?? '') ?: 'Trace';
        $status = self::str($i['status'] ?? '');
        $http = self::str($i['http_status'] ?? '');
        $duration = is_numeric($i['duration_ms'] ?? null) ? (float) $i['duration_ms'] : 0.0;

        $headline = array_filter([
            $status === 'error' ? 'failed' : ($status === 'ok_with_errors' ? 'errors inside' : 'ok'),
            $http !== '' ? 'HTTP '.$http : null,
            MarkdownSnapshot::ms($duration),
        ]);

        $lines = ['# Trace: '.MarkdownSnapshot::text($name, 160).' — '.implode(', ', $headline), ''];

        $facts = [
            'Service' => self::str($i['service'] ?? ''),
            'Status' => $status !== '' ? $status.($http !== '' ? ' (HTTP '.$http.')' : '') : '',
            'Duration' => MarkdownSnapshot::ms($duration).' · '.self::str($i['spans'] ?? '0').' spans',
            'Started' => self::str($i['started_at'] ?? ''),
            'Label' => self::str($i['label'] ?? ''),
        ];

        foreach ($facts as $label => $value) {
            if ($value !== '') {
                $lines[] = '- **'.$label.':** '.MarkdownSnapshot::text($value, 200);
            }
        }

        $lines[] = '- **Trace id:** '.MarkdownSnapshot::code($d->traceId);

        if (($url = DashboardUrl::page('traces/'.$d->traceId)) !== null) {
            $lines[] = '- **Open:** '.$url;
        }

        return implode("\n", $lines);
    }

    private static function flags(TraceDiagnosis $d): string
    {
        $levels = array_count_values(array_column($d->flags, 'level'));
        $errors = $levels['error'] ?? 0;
        $warnings = $levels['warning'] ?? 0;

        $lines = ['## Flags ('.$errors.' '.($errors === 1 ? 'error' : 'errors').', '.$warnings.' '.($warnings === 1 ? 'warning' : 'warnings').')', ''];

        if ($d->flags === []) {
            $lines[] = 'Nothing flagged: no errors, N+1s, repeats, slow queries or telemetry overhead over the thresholds.';

            return implode("\n", $lines);
        }

        foreach (array_slice($d->flags, 0, self::MAX_FLAGS) as $flag) {
            $lines[] = '- **'.$flag['level'].'** · '.$flag['section'].' — '.MarkdownSnapshot::text($flag['message'], 240)
                .(isset($flag['origin']) ? ' — at '.MarkdownSnapshot::code($flag['origin']) : '');
        }

        if (count($d->flags) > self::MAX_FLAGS) {
            $lines[] = '- … and '.(count($d->flags) - self::MAX_FLAGS).' more';
        }

        return implode("\n", $lines);
    }

    private static function timing(TraceDiagnosis $d): string
    {
        $total = array_sum($d->timing);
        $rows = array_filter($d->timing, static fn (float $ms): bool => $ms > 0.0);

        if ($rows === []) {
            return '';
        }

        arsort($rows);
        $lines = ['## Time by category', '', '| category | time | share |', '| --- | ---: | ---: |'];

        foreach ($rows as $category => $ms) {
            $lines[] = '| '.$category.' | '.MarkdownSnapshot::ms($ms).' | '.self::share($ms, $total).' |';
        }

        $lines[] = '';
        $lines[] = '"other" is what no db/cache/http/view/queue span accounts for: the app\'s own code, waiting, the framework.';

        return implode("\n", $lines);
    }

    private static function totals(TraceDiagnosis $d): string
    {
        $labels = [
            'queries' => 'Queries',
            'query_time_ms' => 'Query time',
            'query_shapes' => 'Distinct query shapes',
            'n_plus_one_shapes' => 'N+1 shapes (app-counted)',
            'duplicate_queries_distinct' => 'Duplicate queries, distinct (app-counted)',
            'slow_queries' => 'Slow queries (app-counted)',
            'telemetry_overhead_ms' => 'Telemetry overhead',
        ];

        $lines = ['## Totals', ''];

        foreach ($labels as $key => $label) {
            if (! isset($d->totals[$key])) {
                continue;
            }

            $value = $d->totals[$key];
            $lines[] = '- '.$label.': '.(str_ends_with($key, '_ms') ? MarkdownSnapshot::ms((float) $value) : (string) $value);
        }

        return count($lines) > 2 ? implode("\n", $lines) : '';
    }

    /**
     * @param  list<DetailedTraceException>  $exceptions
     */
    private static function exceptions(array $exceptions): string
    {
        if ($exceptions === []) {
            return '';
        }

        $lines = ['## Exceptions'];

        foreach (array_slice($exceptions, 0, self::MAX_EXCEPTIONS) as $n => $e) {
            $lines[] = '';
            $lines[] = '### '.($n + 1).'. '.MarkdownSnapshot::code($e['type']).($e['message'] !== '' ? ': '.MarkdownSnapshot::text($e['message'], 200) : '');
            $lines[] = '';

            if ($e['file'] !== '') {
                $lines[] = '- **At:** '.MarkdownSnapshot::code($e['file'].($e['line'] > 0 ? ':'.$e['line'] : ''));
            }

            $lines[] = '- **Runtime:** '.$e['source'].match ($e['match']) {
                'time' => ' · matched by service and time (the record carries no trace id)',
                default => '',
            };

            if ($e['group'] !== '') {
                $url = DashboardUrl::page('errors/'.$e['group']);
                $lines[] = '- **Group (fingerprint):** '.MarkdownSnapshot::code($e['group']).($url !== null ? ' — '.$url : '');
            }

            if ($e['stacktrace'] !== '') {
                $frames = StackFrames::app($e['stacktrace'], self::MAX_APP_FRAMES);

                if ($frames['frames'] !== []) {
                    $lines[] = '';
                    $lines[] = 'App frames ('.count($frames['frames']).' of '.$frames['app'].' app frames, '.$frames['total'].' in all; framework frames left out):';
                    $lines[] = '';
                    $lines[] = MarkdownSnapshot::fence(implode("\n", $frames['frames']), 'text');
                }
            }

            if ($e['code'] !== '') {
                $lines[] = '';
                $lines[] = 'Source around the throw (`exception.source`, as recorded):';
                $lines[] = '';
                $lines[] = MarkdownSnapshot::fence(MarkdownSnapshot::limit($e['code'], self::SOURCE_BYTES), 'php');
            }
        }

        if (count($exceptions) > self::MAX_EXCEPTIONS) {
            $lines[] = '';
            $lines[] = '… and '.(count($exceptions) - self::MAX_EXCEPTIONS).' more exceptions in this trace.';
        }

        return implode("\n", $lines);
    }

    /**
     * The last spans that started before the first failure: the earliest
     * failed span below the root, else (a failed or 5xx root with no failing
     * child) the end of the request. The failing span's own ancestors are
     * left out — they are its context, not what happened just before it.
     */
    private static function leadUp(Trace $trace): string
    {
        $root = $trace->root();
        $failed = null;

        foreach ($trace->spans as $span) {
            if ($span !== $root && $span->hasError && ($failed === null || $span->startNano < $failed->startNano)) {
                $failed = $span;
            }
        }

        $rootFailed = $root !== null && ($root->hasError || (int) self::str($root->attributes['http.response.status_code'] ?? '') >= 500);

        if ($failed === null && ! $rootFailed) {
            return '';
        }

        $origin = $root !== null ? $root->startNano : ($trace->spans[0]->startNano ?? 0);
        $cutoff = $failed !== null ? $failed->startNano : ($root !== null ? $root->endNano : PHP_INT_MAX);
        $ancestors = $failed !== null ? self::ancestors($trace, $failed) : [];

        $before = array_values(array_filter(
            $trace->spans,
            static fn (Span $s): bool => $s !== $root && $s !== $failed && ! isset($ancestors[$s->spanId]) && $s->startNano <= $cutoff,
        ));
        usort($before, static fn (Span $a, Span $b): int => $a->startNano <=> $b->startNano);
        $shown = array_slice($before, -self::LEAD_UP);

        $lines = ['## What led up to it', ''];
        $lines[] = $failed !== null
            ? 'The last '.count($shown).' spans that started before the first failing span, '.MarkdownSnapshot::code($failed->name).' at +'.MarkdownSnapshot::ms(($failed->startNano - $origin) / 1_000_000).':'
            : 'The request failed with no failing span below it; its last '.count($shown).' spans:';

        if ($shown === [] && $failed === null) {
            return '';
        }

        $lines[] = '';
        $lines[] = '| start | span | category | duration |';
        $lines[] = '| ---: | --- | --- | ---: |';

        foreach ($shown as $span) {
            $lines[] = self::spanRow($span, $origin, false);
        }

        if ($failed !== null) {
            $lines[] = self::spanRow($failed, $origin, true);
        }

        if (count($before) > count($shown)) {
            $lines[] = '';
            $lines[] = (count($before) - count($shown)).' earlier spans left out.';
        }

        return implode("\n", $lines);
    }

    private static function spanRow(Span $span, int $origin, bool $failed): string
    {
        // A statement says it all; anything else reads as "name · what it did".
        $summary = $span->summary();
        $what = MarkdownSnapshot::cell($summary === null ? $span->name : (isset($span->attributes['db.query.text']) ? $summary : $span->name.' · '.$summary), 110);
        $type = self::str($span->attributes['exception.type'] ?? '');

        if ($failed || $span->hasError) {
            $what = '**failed:** '.$what.($type !== '' ? ' ('.MarkdownSnapshot::cell($type, 80).')' : '');
        }

        return '| +'.MarkdownSnapshot::ms(($span->startNano - $origin) / 1_000_000)
            .' | '.$what
            .' | '.TraceDiagnosis::category($span)
            .' | '.MarkdownSnapshot::ms($span->durationMs()).' |';
    }

    /**
     * @return array<string, true>
     */
    private static function ancestors(Trace $trace, Span $span): array
    {
        $byId = [];

        foreach ($trace->spans as $s) {
            $byId[$s->spanId] = $s;
        }

        $out = [];
        $parent = $span->parentSpanId;

        while ($parent !== null && isset($byId[$parent]) && ! isset($out[$parent])) {
            $out[$parent] = true;
            $parent = $byId[$parent]->parentSpanId;
        }

        return $out;
    }

    /**
     * The flagged query groups — N+1s, identical repeats, slow shapes — in
     * the diagnosis's own order, each with its full statement text.
     */
    private static function queryGroups(TraceDiagnosis $d): string
    {
        $groups = [];

        foreach ($d->flags as $flag) {
            $kind = explode(':', $flag['key'], 2)[0];

            if ($flag['section'] === 'db' && isset(self::QUERY_KINDS[$kind]) && isset($flag['detail']['query'])) {
                $groups[] = [$kind, $flag];
            }
        }

        if ($groups === []) {
            return '';
        }

        $lines = ['## Query groups'];

        foreach (array_slice($groups, 0, self::MAX_QUERY_GROUPS) as [$kind, $flag]) {
            $detail = $flag['detail'];
            $count = self::str($detail['count'] ?? '');
            $lines[] = '';
            $lines[] = '### '.self::QUERY_KINDS[$kind].' — '.$count.($count === '1' ? ' run' : ' runs')
                .' · '.MarkdownSnapshot::ms((float) self::str($detail['total_ms'] ?? 0)).' total'
                .' · max '.MarkdownSnapshot::ms((float) self::str($detail['max_ms'] ?? 0));
            $lines[] = '';

            if ($kind === 'duplicate') {
                $lines[] = '- Identical SQL and bindings (marked by the app from the repeat threshold on).';
            }

            if (isset($flag['origin'])) {
                $function = self::str($detail['function'] ?? '');
                $lines[] = '- **Origin:** '.MarkdownSnapshot::code($flag['origin']).($function !== '' ? ' in '.MarkdownSnapshot::code($function) : '');
            }

            $connection = self::str($detail['connection'] ?? '');
            $shape = self::str($detail['shape'] ?? '');

            if ($connection !== '' || $shape !== '') {
                $lines[] = '- '.implode(' · ', array_filter([
                    $connection !== '' ? '**Connection:** '.MarkdownSnapshot::code($connection) : null,
                    $shape !== '' ? '**Shape:** '.MarkdownSnapshot::code($shape) : null,
                ]));
            }

            $lines[] = '';
            $lines[] = MarkdownSnapshot::fence(MarkdownSnapshot::limit(trim(self::str($detail['query'])), self::SQL_BYTES), 'sql');
        }

        if (count($groups) > self::MAX_QUERY_GROUPS) {
            $lines[] = '';
            $lines[] = '… and '.(count($groups) - self::MAX_QUERY_GROUPS).' more query groups (see the trace view or the `trace_summary` MCP tool).';
        }

        return implode("\n", $lines);
    }

    private static function share(float $ms, float $total): string
    {
        if ($total <= 0.0) {
            return '—';
        }

        $pct = $ms / $total * 100;

        return $pct > 0 && $pct < 1 ? '<1%' : round($pct).'%';
    }

    private static function str(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
