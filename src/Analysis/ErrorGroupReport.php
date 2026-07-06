<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Analysis;

use Cbox\TelemetryUi\Connectors\ConnectionManager;
use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Support\Annotations;
use Cbox\TelemetryUi\Support\ExceptionFingerprint;
use DateTimeImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Everything the UI knows about one error group (fingerprint): occurrences,
 * stats, the newest stacktrace, the request that hit it, root-cause hints.
 * Shared by the drawer panel and the full issue page; memoized per request
 * so a page of cards costs one set of backend queries, not one per card.
 *
 * Backend occurrences come from the structured exception records in Loki
 * (authoritative — they outlive sampled-away traces and carry the full
 * stacktrace); a group with none falls back to browser exception spans in
 * Tempo, matched by the read-side fingerprint.
 *
 * @phpstan-type Occurrence array{nano: int, at: string, traceId: string, service: string, message: string, user: string, frontend: bool, detail: array{type: string, message: string, file: string, line: int, stacktrace: string, source: string, environment: string, release: string, host: string}}
 * @phpstan-type Report array{occurrences: list<Occurrence>, stats: array{count: int, sampled: bool, firstSeen: string, lastSeen: string, source: string, users: int}|null, detail: array{type: string, message: string, file: string, line: int, stacktrace: string, source: string, environment: string, release: string, host: string}|null, request: array{traceId: string, origin: string, method: string, route: string, status: string, user: string}|null, suspect: array{label: string, kind: string, time: string, gap: string, notes: string|null, traceId: string|null, color: string}|null, releases: list<array{release: string, count: int}>}
 */
final class ErrorGroupReport
{
    public const LOOKBACK_DAYS = 30;

    public const SEARCH_LIMIT = 100;

    /** @var array<string, Report> */
    private array $memo = [];

    public function __construct(private readonly ConnectionManager $connections) {}

    /**
     * @param  string  $logSelector  scoped Loki stream selector
     * @param  string  $browserScope  scoped TraceQL conditions for browser exception spans
     * @return Report
     *
     * @throws SourceException
     */
    public function for(string $group, string $logSelector, string $browserScope): array
    {
        $key = $group.'|'.$logSelector.'|'.$browserScope;

        if (isset($this->memo[$key])) {
            return $this->memo[$key];
        }

        $occurrences = $this->backendOccurrences($group, $logSelector);

        // A group is one throw site in one runtime — when Loki has no
        // records for it, it can only be a frontend group.
        if ($occurrences === []) {
            $occurrences = $this->browserOccurrences($group, $browserScope);
        }

        $stats = $this->stats($occurrences);
        $detail = $occurrences[0]['detail'] ?? null;

        // The request that hit it: pulled from the newest occurrence's trace
        // root. The trace may be sampled away — degrade to null quietly.
        $request = ($occurrences[0]['traceId'] ?? '') !== '' ? $this->request($occurrences[0]['traceId']) : null;

        // Root-cause hints: the change event closest before first-seen, and
        // which releases the sampled occurrences carry.
        $suspect = $occurrences !== [] ? $this->suspect($occurrences[array_key_last($occurrences)]['nano'], $logSelector) : null;

        return $this->memo[$key] = [
            'occurrences' => $occurrences,
            'stats' => $stats,
            'detail' => $detail,
            'request' => $request,
            'suspect' => $suspect,
            'releases' => $this->releases($occurrences),
        ];
    }

    /**
     * @return list<Occurrence>
     *
     * @throws SourceException
     */
    private function backendOccurrences(string $group, string $logSelector): array
    {
        [$start, $end] = $this->window();

        $entries = $this->connections->logs()->query(
            $logSelector.' | exception_group="'.addcslashes($group, '"\\').'"',
            $start,
            $end,
            limit: self::SEARCH_LIMIT,
        );

        $occurrences = [];

        foreach ($entries as $entry) {
            if (($entry->labels['exception_group'] ?? '') !== $group) {
                continue;
            }

            $label = static fn (string $key): string => $entry->labels[$key] ?? '';

            $occurrences[] = [
                'nano' => $entry->timestampNano,
                'at' => Carbon::createFromTimestamp(intdiv($entry->timestampNano, 1_000_000_000))->format('d/m H:i:s'),
                'traceId' => $label('trace_id'),
                'service' => $label('service_name'),
                'message' => $label('exception_message'),
                'user' => $label('enduser_id'),
                'frontend' => false,
                'detail' => [
                    'type' => $label('exception_type'),
                    'message' => $label('exception_message'),
                    'file' => $label('exception_file'),
                    'line' => (int) $label('exception_line'),
                    'stacktrace' => $label('exception_stacktrace'),
                    'source' => $label('exception_source'),
                    'environment' => $label('deployment_environment_name'),
                    'release' => $label('deployment_id'),
                    'host' => $label('host_name'),
                ],
            ];
        }

        usort($occurrences, static fn (array $a, array $b): int => $b['nano'] <=> $a['nano']);

        return $occurrences;
    }

    /**
     * Frontend occurrences: browser exception spans in Tempo. The ingest
     * doesn't stamp a fingerprint, so match on the one computed read-side.
     * Browser errors carry no stacktrace — type/message/file:line is all
     * the SDK ships.
     *
     * @return list<Occurrence>
     *
     * @throws SourceException
     */
    private function browserOccurrences(string $group, string $browserScope): array
    {
        [$start, $end] = $this->window();

        $traceql = '{ '.$browserScope
            .' } | select(span.exception.type, span.exception.message, span.exception.file, span.exception.line)';

        $results = $this->connections->traces()->search($traceql, $start, $end, limit: self::SEARCH_LIMIT);

        $occurrences = [];

        foreach ($results as $summary) {
            foreach ($summary->matchedSpans as $span) {
                $attr = static fn (string $key): string => is_scalar($value = $span->attributes[$key] ?? null) ? (string) $value : '';

                $type = $attr('exception.type');
                $file = $attr('exception.file');
                $line = (int) $attr('exception.line');

                if ($type === '' || ExceptionFingerprint::compute($type, $file, $line) !== $group) {
                    continue;
                }

                $occurrences[] = [
                    'nano' => $span->startNano,
                    'at' => Carbon::createFromTimestamp(intdiv($span->startNano, 1_000_000_000))->format('d/m H:i:s'),
                    'traceId' => $summary->traceId,
                    'service' => $summary->rootServiceName,
                    'message' => $attr('exception.message'),
                    'user' => $attr('enduser.id'),
                    'frontend' => true,
                    'detail' => [
                        'type' => $type,
                        'message' => $attr('exception.message'),
                        'file' => $file,
                        'line' => $line,
                        'stacktrace' => '',
                        'source' => '',
                        'environment' => '',
                        'release' => '',
                        'host' => '',
                    ],
                ];
            }
        }

        usort($occurrences, static fn (array $a, array $b): int => $b['nano'] <=> $a['nano']);

        return $occurrences;
    }

    /**
     * @param  list<Occurrence>  $occurrences
     * @return array{count: int, sampled: bool, firstSeen: string, lastSeen: string, source: string, users: int}|null
     */
    private function stats(array $occurrences): ?array
    {
        if ($occurrences === []) {
            return null;
        }

        $users = [];

        foreach ($occurrences as $occurrence) {
            if ($occurrence['user'] !== '') {
                $users[$occurrence['user']] = true;
            }
        }

        return [
            'count' => count($occurrences),
            'sampled' => count($occurrences) >= self::SEARCH_LIMIT,
            'firstSeen' => Carbon::createFromTimestamp(intdiv($occurrences[array_key_last($occurrences)]['nano'], 1_000_000_000))->diffForHumans(),
            'lastSeen' => Carbon::createFromTimestamp(intdiv($occurrences[0]['nano'], 1_000_000_000))->diffForHumans(),
            'source' => $occurrences[0]['frontend'] ? 'frontend' : 'backend',
            'users' => count($users),
        ];
    }

    /**
     * @return array{traceId: string, origin: string, method: string, route: string, status: string, user: string}|null
     */
    private function request(string $traceId): ?array
    {
        if (preg_match('/^[0-9a-f]{16,32}$/', $traceId) !== 1) {
            return null;
        }

        try {
            $trace = $this->connections->traces()->trace($traceId);
        } catch (SourceException) {
            return null;
        }

        $root = $trace->root();

        if ($root === null) {
            return null;
        }

        $attr = static fn (string $key): string => is_scalar($value = $root->attributes[$key] ?? null) ? (string) $value : '';

        return [
            'traceId' => $traceId,
            'origin' => $root->name,
            'method' => $attr('http.request.method'),
            'route' => $attr('http.route'),
            'status' => $attr('http.response.status_code'),
            'user' => $attr('enduser.id'),
        ];
    }

    /**
     * @return array{label: string, kind: string, time: string, gap: string, notes: string|null, traceId: string|null, color: string}|null
     */
    private function suspect(int $firstSeenNano, string $logSelector): ?array
    {
        $firstSeenMs = intdiv($firstSeenNano, 1_000_000);

        foreach (app(Annotations::class)->lookback($logSelector) as $annotation) {
            // Newest-first: the first marker at/before first-seen is the closest.
            if ($annotation->timestampMs > $firstSeenMs) {
                continue;
            }

            if ($firstSeenMs - $annotation->timestampMs > 48 * 3_600_000) {
                return null; // too long before — not a credible suspect.
            }

            return [
                'label' => $annotation->label,
                'kind' => $annotation->kind,
                'time' => date('d/m H:i', (int) ($annotation->timestampMs / 1000)),
                'gap' => Carbon::createFromTimestampMs((int) $annotation->timestampMs)
                    ->diffForHumans(Carbon::createFromTimestampMs($firstSeenMs), ['syntax' => Carbon::DIFF_ABSOLUTE, 'parts' => 1]),
                'notes' => $annotation->notes,
                'traceId' => $annotation->traceId,
                'color' => $annotation->color,
            ];
        }

        return null;
    }

    /**
     * @param  list<Occurrence>  $occurrences
     * @return list<array{release: string, count: int}>
     */
    private function releases(array $occurrences): array
    {
        $releases = [];

        foreach ($occurrences as $occurrence) {
            $release = $occurrence['detail']['release'];

            if ($release !== '') {
                $releases[$release] = ($releases[$release] ?? 0) + 1;
            }
        }

        arsort($releases);

        return array_map(
            static fn (string $release, int $count): array => ['release' => $release, 'count' => $count],
            array_keys(array_slice($releases, 0, 5, true)),
            array_values(array_slice($releases, 0, 5, true)),
        );
    }

    /**
     * @return array{DateTimeImmutable, DateTimeImmutable}
     */
    private function window(): array
    {
        $end = new DateTimeImmutable;

        return [$end->modify('-'.self::LOOKBACK_DAYS.' days'), $end];
    }

    /**
     * Prefilled compose-ticket draft for this error group.
     *
     * @param  array{count: int, sampled: bool, firstSeen: string, lastSeen: string, source: string, users: int}|null  $stats
     * @param  array{type: string, message: string, file: string, line: int, stacktrace: string, source: string, environment: string, release: string, host: string}|null  $detail
     * @return array{title: string, body: string, labels: list<string>}
     */
    public function draft(string $group, ?array $stats, ?array $detail): array
    {
        $type = $detail['type'] ?? '';
        $title = trim(($type !== '' ? class_basename($type) : 'Error '.$group).': '.Str::limit($detail['message'] ?? '', 90));

        $lines = array_filter([
            $type !== '' ? '**'.$type.'**' : null,
            ($detail['message'] ?? '') !== '' ? $detail['message'] : null,
            '',
            '- group: `'.$group.'`',
            ($detail['file'] ?? '') !== '' ? '- at: `'.$detail['file'].':'.($detail['line'] ?? 0).'`' : null,
            $stats !== null ? '- occurrences: '.$stats['count'].($stats['sampled'] ? '+' : '').' (last '.self::LOOKBACK_DAYS.' days, '.$stats['source'].')' : null,
            $stats !== null ? '- first seen: '.$stats['firstSeen'].' · last seen: '.$stats['lastSeen'] : null,
            '',
            ($detail['stacktrace'] ?? '') !== '' ? "```\n".Str::limit($detail['stacktrace'], 2000)."\n```" : null,
        ], static fn (?string $line): bool => $line !== null);

        return [
            'title' => rtrim($title, ': '),
            'body' => implode("\n", $lines),
            'labels' => ['bug'],
        ];
    }

    /**
     * A self-contained Markdown brief of the whole group for pasting into an
     * LLM ("here's the error, help me fix it"): identity and stats, the
     * request that triggered it, the suspect change, the releases it rode in
     * on, and the freshest stacktrace — everything the page knows, in one
     * block, so the model needs no follow-up context.
     *
     * @param  Report  $report
     */
    public function llmMarkdown(string $group, array $report): string
    {
        $stats = $report['stats'];
        $detail = $report['detail'];
        $request = $report['request'];
        $suspect = $report['suspect'];

        $type = $detail['type'] ?? '';
        $message = $detail['message'] ?? '';

        $heading = $type !== '' ? class_basename($type) : 'Error '.$group;
        $lines = ['# '.rtrim($heading.($message !== '' ? ': '.Str::limit($message, 100) : ''), ': ')];

        $overview = array_filter([
            $type !== '' ? '- **Exception:** `'.$type.'`' : null,
            $message !== '' ? '- **Message:** '.$message : null,
            ($detail['file'] ?? '') !== '' ? '- **Location:** `'.$detail['file'].':'.($detail['line'] ?? 0).'`' : null,
            '- **Group (fingerprint):** `'.$group.'`',
            $stats !== null ? '- **Runtime:** '.$stats['source'] : null,
            $stats !== null ? '- **Occurrences:** '.$stats['count'].($stats['sampled'] ? '+' : '').' in the last '.self::LOOKBACK_DAYS.' days' : null,
            $stats !== null ? '- **First seen:** '.$stats['firstSeen'].' · **last seen:** '.$stats['lastSeen'] : null,
            ($stats['users'] ?? 0) > 0 ? '- **Users affected:** '.$stats['users'].($stats['sampled'] ? '+' : '') : null,
            ($detail['environment'] ?? '') !== '' ? '- **Environment:** '.$detail['environment'] : null,
            ($detail['release'] ?? '') !== '' ? '- **Release:** '.$detail['release'] : null,
            ($detail['host'] ?? '') !== '' ? '- **Host:** '.$detail['host'] : null,
        ], static fn (?string $line): bool => $line !== null);

        if ($overview !== []) {
            $lines[] = '';
            $lines[] = '## Overview';
            $lines = array_merge($lines, $overview);
        }

        if ($request !== null) {
            $endpoint = trim(($request['method'] !== '' ? $request['method'].' ' : '')
                .($request['route'] !== '' ? $request['route'] : $request['origin']));
            $reqLines = array_filter([
                $endpoint !== '' ? '- **Endpoint:** '.$endpoint : null,
                $request['status'] !== '' ? '- **Response status:** '.$request['status'] : null,
                $request['user'] !== '' ? '- **User:** '.$request['user'] : null,
            ], static fn (?string $line): bool => $line !== null);

            if ($reqLines !== []) {
                $lines[] = '';
                $lines[] = '## Request that hit it';
                $lines = array_merge($lines, $reqLines);
            }
        }

        if ($suspect !== null) {
            $lines[] = '';
            $lines[] = '## Suspect change';
            $lines[] = $suspect['label'].' ('.$suspect['kind'].') at '.$suspect['time']
                .' — first seen '.$suspect['gap'].' later.';
            if (($suspect['notes'] ?? '') !== '') {
                $lines[] = (string) $suspect['notes'];
            }
        }

        if ($report['releases'] !== []) {
            $lines[] = '';
            $lines[] = '## Releases seen';
            foreach ($report['releases'] as $release) {
                $lines[] = '- `'.$release['release'].'` — '.$release['count'];
            }
        }

        if (($detail['stacktrace'] ?? '') !== '') {
            $lines[] = '';
            $lines[] = '## Stacktrace';
            $lines[] = '```';
            $lines[] = Str::limit((string) $detail['stacktrace'], 6000);
            $lines[] = '```';
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * A token-ish group id — never raw LogQL/TraceQL metacharacters.
     */
    public static function validId(string $group): bool
    {
        return preg_match('#^[\w.:/-]{1,64}$#', $group) === 1;
    }
}
