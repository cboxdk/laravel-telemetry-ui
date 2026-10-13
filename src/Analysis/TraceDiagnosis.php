<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Analysis;

use Cbox\TelemetryUi\Explore\TraceExceptions;
use Cbox\TelemetryUi\Queries\Results\Span;
use Cbox\TelemetryUi\Queries\Results\SpanKind;
use Cbox\TelemetryUi\Queries\Results\Trace;

/**
 * What one trace did and what is wrong with it, read off the spans alone: who
 * it was (root, service, status, label), where the time went by category, the
 * root span's cost tallies, the query shapes it ran and the problems worth a
 * look — errors first, then N+1s, identical repeats, slow queries and the
 * telemetry's own overhead.
 *
 * Built for agents (the `trace_summary` / `compare_traces` MCP tools), so it
 * keeps a stable key on every flag and shape: two traces of the same endpoint
 * can be compared flag-by-flag and shape-by-shape, before and after a fix.
 *
 * laravel-telemetry stamps `laravel.db.query.shape` (a hash of the normalised
 * statement) and `laravel.db.query.repeat` (`n_plus_one` | `duplicate`, from
 * the threshold-crossing run onward) on query spans. Every span sharing a
 * flagged shape is part of the group, so counts are per shape, not per flagged
 * span. Traces from before those attributes group by the statement text, and
 * a statement that ran at least the repeat threshold times is reported as a
 * repeat whose kind cannot be told apart.
 *
 * @phpstan-type Flag array{level: 'error'|'warning', section: string, message: string, origin?: string, key: string, detail?: array<string, mixed>}
 * @phpstan-type Shape array{key: string, text: string, connection: string, count: int, totalMs: float, maxMs: float, slow: int, repeat: string, nPlusOne: int, duplicate: int, origin: string, function: string, spanIds: list<string>}
 * @phpstan-type Thresholds array{slow_query_ms: float, overhead_ms: float, overhead_ratio: float, repeat_threshold: int}
 */
final readonly class TraceDiagnosis
{
    /** Timing categories, in display order. */
    public const CATEGORIES = ['db', 'cache', 'http', 'view', 'queue', 'other'];

    /** Root-span tallies → short output keys. */
    private const TOTALS = [
        'db.query.count' => 'queries',
        'db.query.time_ms' => 'query_time_ms',
        'db.query.n_plus_one.count' => 'n_plus_one_shapes',
        'db.query.duplicate.count' => 'duplicate_queries_distinct',
        'db.query.slow.count' => 'slow_queries',
        'telemetry.overhead_ms' => 'telemetry_overhead_ms',
    ];

    private const TEXT_LIMIT = 120;

    /**
     * @param  array<string, mixed>  $identity
     * @param  array<string, float>  $timing  ms per category (non-overlapping)
     * @param  array<string, float|int>  $totals
     * @param  list<Flag>  $flags  errors first, then warnings
     * @param  array<string, Shape>  $shapes  keyed by shape (or a text hash on older data)
     */
    public function __construct(
        public string $traceId,
        public array $identity,
        public array $timing,
        public array $totals,
        public array $flags,
        public array $shapes,
    ) {}

    /**
     * @param  list<array{group: string, type: string, message: string, file: string, line: int, source: string, match: string}>  $exceptions  from {@see TraceExceptions}
     * @param  array<string, float|int>  $thresholds
     */
    public static function analyze(Trace $trace, array $exceptions = [], array $thresholds = []): self
    {
        $t = self::thresholds($thresholds);
        $root = $trace->root();
        $rootAttributes = $root !== null ? $root->attributes : [];
        $durationMs = $root !== null ? $root->durationMs() : $trace->durationMs();

        $shapes = self::shapes($trace, $t);
        $totals = self::totals($rootAttributes, $shapes);

        return new self(
            traceId: $trace->traceId,
            identity: self::identity($trace, $root, $durationMs),
            timing: self::timing($trace, $root, $durationMs),
            totals: $totals,
            flags: [
                ...self::errorFlags($trace, $root, $exceptions),
                ...self::queryFlags($shapes, $t),
                ...self::slowTallyFlag($totals, $shapes),
                ...self::overheadFlag($totals, $durationMs, $t),
            ],
            shapes: $shapes,
        );
    }

    /**
     * @param  array<string, float|int>  $given
     * @return Thresholds
     */
    public static function thresholds(array $given = []): array
    {
        return [
            'slow_query_ms' => (float) ($given['slow_query_ms'] ?? 100),
            'overhead_ms' => (float) ($given['overhead_ms'] ?? 5),
            'overhead_ratio' => (float) ($given['overhead_ratio'] ?? 0.05),
            'repeat_threshold' => max(2, (int) ($given['repeat_threshold'] ?? 3)),
        ];
    }

    /** The category a span's time belongs to; the root is never categorised. */
    public static function category(Span $span): string
    {
        $a = $span->attributes;
        $name = $span->name;
        $system = self::str($a['db.system.name'] ?? $a['db.system'] ?? '');

        return match (true) {
            $name === 'redis.connect', str_starts_with($name, 'cache.'), str_starts_with($name, 'redis'),
            isset($a['cache.key']), isset($a['cache.store']), $system === 'redis' => 'cache',
            $name === 'db.connect', isset($a['db.query.text']), $system !== '' => 'db',
            $span->kind === SpanKind::Client && ! $span->isBrowser()
                && (isset($a['server.address']) || isset($a['url.full']) || isset($a['http.url'])) => 'http',
            isset($a['view.name']), str_starts_with($name, 'view'), str_starts_with($name, 'livewire.') => 'view',
            $span->kind === SpanKind::Producer, isset($a['messaging.system']), isset($a['messaging.destination.name']) => 'queue',
            default => 'other',
        };
    }

    /** A statement as one short line. */
    public static function shortText(string $text, int $max = self::TEXT_LIMIT): string
    {
        $text = trim((string) preg_replace('/\s+/', ' ', $text));

        return mb_strlen($text) > $max ? mb_substr($text, 0, $max).'…' : $text;
    }

    /**
     * @return array<string, mixed>
     */
    private static function identity(Trace $trace, ?Span $root, float $durationMs): array
    {
        $a = $root !== null ? $root->attributes : [];
        $status = self::str($a['http.response.status_code'] ?? '');

        return [
            'trace_id' => $trace->traceId,
            'name' => $root?->name,
            'service' => $root?->serviceName,
            'kind' => $root?->kind->value,
            'status' => $root !== null && $root->hasError ? 'error' : ($trace->hasError() ? 'ok_with_errors' : 'ok'),
            'http_status' => $status !== '' ? (int) $status : null,
            'duration_ms' => round($durationMs, 1),
            'label' => self::str($a['laravel.trace.label'] ?? '') ?: null,
            'started_at' => $root !== null ? gmdate('Y-m-d\TH:i:s\Z', intdiv($root->startNano, 1_000_000_000)) : null,
            'spans' => count($trace->spans),
        ];
    }

    /**
     * Time per category without double counting: a span is counted only when
     * no (non-root) ancestor already claimed it — an HTTP call made while
     * rendering a view is the view's time. What is left of the root is
     * `other` (the app's own code, waiting, the framework).
     *
     * @return array<string, float>
     */
    private static function timing(Trace $trace, ?Span $root, float $durationMs): array
    {
        $byId = [];

        foreach ($trace->spans as $span) {
            $byId[$span->spanId] = $span;
        }

        $timing = array_fill_keys(self::CATEGORIES, 0.0);

        foreach ($trace->spans as $span) {
            if ($span === $root) {
                continue;
            }

            $category = self::category($span);

            if ($category === 'other' || self::claimedByAncestor($span, $byId, $root)) {
                continue;
            }

            $timing[$category] += $span->durationMs();
        }

        $claimed = array_sum($timing);
        $timing['other'] = max(0.0, $durationMs - $claimed);

        return array_map(static fn (float $ms): float => round($ms, 1), $timing);
    }

    /**
     * @param  array<string, Span>  $byId
     */
    private static function claimedByAncestor(Span $span, array $byId, ?Span $root): bool
    {
        $seen = [];
        $parentId = $span->parentSpanId;

        while ($parentId !== null && isset($byId[$parentId]) && ! isset($seen[$parentId])) {
            $seen[$parentId] = true;
            $parent = $byId[$parentId];

            if ($parent !== $root && self::category($parent) !== 'other') {
                return true;
            }

            $parentId = $parent->parentSpanId;
        }

        return false;
    }

    /**
     * Query spans grouped by shape (statement text on older data).
     *
     * @param  Thresholds  $t
     * @return array<string, Shape>
     */
    private static function shapes(Trace $trace, array $t): array
    {
        $shapes = [];

        foreach ($trace->spans as $span) {
            $text = self::str($span->attributes['db.query.text'] ?? '');

            if ($text === '') {
                continue;
            }

            $a = $span->attributes;
            $connection = self::str($a['laravel.db.connection'] ?? $a['db.system.name'] ?? '');
            $shape = self::str($a['laravel.db.query.shape'] ?? '');
            $key = $shape !== '' ? $shape : 'text:'.substr(hash('xxh128', $connection."\0".$text), 0, 16);
            $ms = $span->durationMs();
            $repeat = self::str($a['laravel.db.query.repeat'] ?? '');

            $row = $shapes[$key] ?? [
                'key' => $key, 'text' => $text, 'connection' => $connection,
                'count' => 0, 'totalMs' => 0.0, 'maxMs' => 0.0, 'slow' => 0,
                'repeat' => '', 'nPlusOne' => 0, 'duplicate' => 0, 'origin' => '', 'function' => '', 'spanIds' => [],
            ];

            $row['count']++;
            $row['totalMs'] += $ms;
            $row['maxMs'] = max($row['maxMs'], $ms);
            $row['slow'] += $ms >= $t['slow_query_ms'] ? 1 : 0;

            // A span carries one marker; a shape can earn both (an N+1 whose
            // loop also re-reads one row). The N+1 is the larger fix.
            if ($repeat === 'n_plus_one') {
                $row['nPlusOne']++;
                $row['repeat'] = 'n_plus_one';
            } elseif ($repeat === 'duplicate') {
                $row['duplicate']++;
                $row['repeat'] = $row['repeat'] === '' ? 'duplicate' : $row['repeat'];
            }

            if ($row['origin'] === '' && ($file = self::str($a['code.file.path'] ?? '')) !== '') {
                $line = self::str($a['code.line.number'] ?? '');
                $row['origin'] = $file.($line !== '' ? ':'.$line : '');
                $row['function'] = self::str($a['code.function.name'] ?? '');
            }

            if (count($row['spanIds']) < 10) {
                $row['spanIds'][] = $span->spanId;
            }

            $shapes[$key] = $row;
        }

        // Older data: no shape and no repeat marker anywhere — a statement that
        // ran at the repeat threshold is the old "duplicate" (really an N+1).
        $legacy = $shapes !== [] && array_filter(
            $trace->spans,
            static fn (Span $s): bool => isset($s->attributes['laravel.db.query.shape']) || isset($s->attributes['laravel.db.query.repeat']),
        ) === [];

        if ($legacy) {
            foreach ($shapes as $key => $row) {
                if ($row['count'] >= $t['repeat_threshold']) {
                    $shapes[$key]['repeat'] = 'repeated';
                }
            }
        }

        uasort($shapes, static fn (array $a, array $b): int => $b['totalMs'] <=> $a['totalMs']);

        return $shapes;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<string, Shape>  $shapes
     * @return array<string, float|int>
     */
    private static function totals(array $attributes, array $shapes): array
    {
        $totals = [];

        foreach (self::TOTALS as $attribute => $key) {
            $value = $attributes[$attribute] ?? null;

            if (is_numeric($value)) {
                $totals[$key] = str_ends_with($key, '_ms') ? round((float) $value, 2) : (int) $value;
            }
        }

        // A root without the tallies (older data, a non-Laravel root) still
        // has its query spans to count.
        if (! isset($totals['queries']) && $shapes !== []) {
            $totals['queries'] = array_sum(array_column($shapes, 'count'));
            $totals['query_time_ms'] = round(array_sum(array_column($shapes, 'totalMs')), 2);
        }

        $totals['query_shapes'] = count($shapes);

        return $totals;
    }

    /**
     * Exceptions, a 5xx response, failed jobs and other failed spans.
     *
     * @param  list<array{group: string, type: string, message: string, file: string, line: int, source: string, match: string}>  $exceptions
     * @return list<Flag>
     */
    private static function errorFlags(Trace $trace, ?Span $root, array $exceptions): array
    {
        $flags = [];

        foreach ($exceptions as $exception) {
            $flags[] = self::flag('error', 'exception', self::shortText($exception['type'].($exception['message'] !== '' ? ': '.$exception['message'] : ''), 200),
                'exception:'.($exception['group'] !== '' ? $exception['group'] : $exception['type']),
                $exception['file'] !== '' ? $exception['file'].($exception['line'] > 0 ? ':'.$exception['line'] : '') : '',
                array_filter([
                    'group' => $exception['group'],
                    'message' => $exception['message'],
                    'source' => $exception['source'],
                    'match' => $exception['match'] === 'time' ? 'by service and time (no trace id on the record)' : '',
                ]),
            );
        }

        $status = (int) self::str($root !== null ? ($root->attributes['http.response.status_code'] ?? '') : '');

        if ($status >= 500) {
            $flags[] = self::flag('error', 'request', 'Responded '.$status, 'http:5xx');
        }

        /** @var array<string, array{section: string, name: string, count: int, type: string}> $failed */
        $failed = [];

        foreach ($trace->spans as $span) {
            if (! $span->hasError) {
                continue;
            }

            $a = $span->attributes;
            $isJob = $span->kind === SpanKind::Consumer || isset($a['laravel.job.class']);

            // The root failing is the consequence of what is already flagged.
            if ($span === $root && ! $isJob && ($flags !== [])) {
                continue;
            }

            $name = $isJob ? self::str($a['laravel.job.class'] ?? $a['messaging.destination.name'] ?? $span->name) : $span->name;
            $section = $isJob ? 'job' : 'span';
            $key = $section.':'.$name;

            $failed[$key] ??= ['section' => $section, 'name' => $name, 'count' => 0, 'type' => self::str($a['exception.type'] ?? '')];
            $failed[$key]['count']++;
        }

        foreach ($failed as $key => $row) {
            $what = $row['section'] === 'job' ? 'Job '.$row['name'].' failed' : ($row['count'] > 1 ? $row['count'].'× ' : '').$row['name'].' failed';
            $flags[] = self::flag('error', $row['section'], $what.($row['type'] !== '' ? ': '.$row['type'] : ''), $key);
        }

        return $flags;
    }

    /**
     * N+1 groups, identical repeats, then slow queries — each by shape.
     *
     * @param  array<string, Shape>  $shapes
     * @param  Thresholds  $t
     * @return list<Flag>
     */
    private static function queryFlags(array $shapes, array $t): array
    {
        $byKind = ['n_plus_one' => [], 'duplicate' => [], 'repeated' => []];

        foreach ($shapes as $shape) {
            if ($shape['repeat'] === 'repeated') {
                $byKind['repeated'][] = $shape;
            }

            if ($shape['nPlusOne'] > 0) {
                $byKind['n_plus_one'][] = $shape;
            }

            if ($shape['duplicate'] > 0) {
                $byKind['duplicate'][] = $shape;
            }
        }

        $flags = [];

        foreach ($byKind as $kind => $rows) {
            usort($rows, static fn (array $a, array $b): int => $b['count'] <=> $a['count']);

            foreach ($rows as $shape) {
                $text = self::shortText($shape['text']);
                $message = match ($kind) {
                    'n_plus_one' => 'N+1: '.$shape['count'].'× '.$text,
                    // Marked from the threshold-crossing run onward, so the
                    // identical runs are at least the marked ones plus the
                    // runs before the threshold; the shape may hold more.
                    'duplicate' => 'Duplicate (identical SQL and bindings): ≥'
                        .min($shape['count'], $shape['duplicate'] + $t['repeat_threshold'] - 1).'× of '.$shape['count'].' runs: '.$text,
                    default => 'Repeated (older data: N+1 or identical): '.$shape['count'].'× '.$text,
                };

                $flags[] = self::flag('warning', 'db', $message, $kind.':'.$shape['key'], $shape['origin'], self::shapeDetail($shape));
            }
        }

        $slow = array_filter($shapes, static fn (array $s): bool => $s['slow'] > 0);
        usort($slow, static fn (array $a, array $b): int => $b['maxMs'] <=> $a['maxMs']);

        foreach ($slow as $shape) {
            $times = $shape['slow'] > 1 ? $shape['slow'].'× ≥'.self::num($t['slow_query_ms']).'ms, max ' : '';
            $flags[] = self::flag('warning', 'db', 'Slow query ('.$times.self::num($shape['maxMs']).'ms): '.self::shortText($shape['text']),
                'slow:'.$shape['key'], $shape['origin'], self::shapeDetail($shape));
        }

        return $flags;
    }

    /**
     * The root counts slow queries (laravel-telemetry 3.5+, against its own
     * `slow_span_ms`) that no exported span shows as slow here — a different
     * threshold, or query spans that were never exported. Say so rather than
     * report nothing.
     *
     * @param  array<string, float|int>  $totals
     * @param  array<string, Shape>  $shapes
     * @return list<Flag>
     */
    private static function slowTallyFlag(array $totals, array $shapes): array
    {
        $count = (int) ($totals['slow_queries'] ?? 0);

        if ($count === 0 || array_filter($shapes, static fn (array $s): bool => $s['slow'] > 0) !== []) {
            return [];
        }

        return [self::flag('warning', 'db', $count.' slow '.($count === 1 ? 'query' : 'queries').' counted by the app, none slow among the spans read here (check traces.diagnostics.slow_query_ms against the emitter\'s slow_span_ms)', 'slow:root')];
    }

    /**
     * @param  array<string, float|int>  $totals
     * @param  Thresholds  $t
     * @return list<Flag>
     */
    private static function overheadFlag(array $totals, float $durationMs, array $t): array
    {
        $overhead = (float) ($totals['telemetry_overhead_ms'] ?? 0);

        if ($overhead < $t['overhead_ms'] || $durationMs <= 0.0 || $overhead / $durationMs < $t['overhead_ratio']) {
            return [];
        }

        return [self::flag('warning', 'telemetry', sprintf(
            'Telemetry overhead %sms (%s%% of the trace)',
            self::num($overhead),
            self::num($overhead / $durationMs * 100),
        ), 'overhead')];
    }

    /**
     * @param  Shape  $shape
     * @return array<string, mixed>
     */
    private static function shapeDetail(array $shape): array
    {
        return [
            'shape' => str_starts_with($shape['key'], 'text:') ? null : $shape['key'],
            'query' => $shape['text'],
            'connection' => $shape['connection'],
            'count' => $shape['count'],
            'flagged_n_plus_one' => $shape['nPlusOne'],
            'flagged_duplicate' => $shape['duplicate'],
            'total_ms' => round($shape['totalMs'], 2),
            'max_ms' => round($shape['maxMs'], 2),
            'function' => $shape['function'],
            'span_ids' => $shape['spanIds'],
        ];
    }

    /**
     * @param  'error'|'warning'  $level
     * @param  array<string, mixed>  $detail
     * @return Flag
     */
    private static function flag(string $level, string $section, string $message, string $key, string $origin = '', array $detail = []): array
    {
        $flag = ['level' => $level, 'section' => $section, 'message' => $message, 'key' => $key];

        if ($origin !== '') {
            $flag['origin'] = $origin;
        }

        if ($detail !== []) {
            $flag['detail'] = $detail;
        }

        return $flag;
    }

    private static function num(float $value): string
    {
        return $value >= 10 ? (string) round($value) : (string) round($value, 1);
    }

    private static function str(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
