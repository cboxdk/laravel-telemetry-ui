<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Explore;

use Cbox\TelemetryUi\Connectors\ConnectionManager;
use Cbox\TelemetryUi\Dimensions\Dimensions;
use Cbox\TelemetryUi\Http\Api\RequestScope;
use Cbox\TelemetryUi\Queries\Ir\LogQuery;
use Cbox\TelemetryUi\Queries\Ir\MatchOp;
use Cbox\TelemetryUi\Queries\Ir\TraceCondition;
use Cbox\TelemetryUi\Queries\Ir\TraceOp;
use Cbox\TelemetryUi\Support\ExceptionFingerprint;
use Cbox\TelemetryUi\Support\ScopeLabels;

/**
 * Explore over errors: exception occurrences from BOTH places they live —
 * Loki exception records (backend, authoritative) and browser exception spans
 * in Tempo (frontend, fingerprinted read-side) — grouped by the shared
 * `exception.group` fingerprint into one Sentry-style issue list.
 *
 * @phpstan-type Occurrence array{group: string, type: string, message: string, nano: int, traceId: string|null, service: string, user: string, frontend: bool, attributes: array<string, string>}
 */
final class ErrorExplorer
{
    public const DEFAULT_LIMIT = 500;

    public function __construct(
        private readonly ConnectionManager $connections,
        private readonly Dimensions $dimensions,
    ) {}

    /**
     * The exception-record stream this scope reads: every log line carrying an
     * `exception_group`, narrowed by the scope's filters. `source` is decided
     * read-side (frontend records come from spans), so it isn't a label here.
     */
    public function query(RequestScope $scope): LogQuery
    {
        $query = $scope->logQuery()->whereLabel('exception_group', MatchOp::Neq, '');

        foreach ($scope->where as $filter) {
            $op = MatchOp::tryFrom($filter->op);

            if ($op === null || in_array($filter->key, ['source'], true)) {
                continue;
            }

            // A derived dimension filters its source label, encoded — the same
            // rule the log explorer applies, so both halves of the Errors page
            // (records here, frontend spans in Tempo) select the same set.
            $derived = $this->dimensions->resolve($filter->key)->derived;

            if ($derived !== null) {
                $label = self::label($derived->from);
                $query = $filter->value === ''
                    ? $query->whereLabel($label, $op === MatchOp::Neq ? MatchOp::Re : MatchOp::Nre, $derived->regex())
                    : $query->whereLabel($label, $op, in_array($op, [MatchOp::Re, MatchOp::Nre], true) ? $derived->regex('(?:'.$filter->value.')') : $derived->encode($filter->value));

                continue;
            }

            $query = $query->whereLabel(self::label($filter->key), $op, $filter->value);
        }

        return $query;
    }

    /**
     * @return list<Occurrence>
     */
    public function occurrences(RequestScope $scope, int $limit = self::DEFAULT_LIMIT): array
    {
        [$start, $end] = $scope->range();

        $query = $this->query($scope);

        $wantSource = null;

        foreach ($scope->where as $filter) {
            if ($filter->key === 'source' && $filter->op === '=') {
                $wantSource = $filter->value;
            }
        }

        $occurrences = [];

        if ($wantSource !== 'frontend') {
            foreach ($this->connections->logs()->query($query, $start, $end, $limit) as $entry) {
                $group = $entry->labels['exception_group'] ?? '';

                if ($group === '') {
                    continue;
                }

                $occurrences[] = [
                    'group' => $group,
                    'type' => $entry->labels['exception_type'] ?? '',
                    'message' => $entry->labels['exception_message'] ?? $entry->line,
                    'nano' => $entry->timestampNano,
                    'traceId' => ($entry->labels['trace_id'] ?? '') !== '' ? $entry->labels['trace_id'] : null,
                    'service' => $entry->labels[ScopeLabels::logs('service')] ?? '',
                    'user' => $entry->labels['user_id'] ?? '',
                    'frontend' => false,
                    'attributes' => [
                        'exception.type' => $entry->labels['exception_type'] ?? '',
                        'service.name' => $entry->labels[ScopeLabels::logs('service')] ?? '',
                        'user.id' => $entry->labels['user_id'] ?? '',
                        'source' => 'backend',
                    ],
                ];
            }
        }

        if ($wantSource !== 'backend') {
            $conditions = [
                TraceCondition::token('span.browser', TraceOp::Eq, 'true'),
                TraceCondition::nil('span.exception.type'),
                ...TraceFilters::conditions(array_values(array_filter($scope->where, static fn ($f): bool => $f->key !== 'source')), $this->dimensions),
            ];

            $traceQuery = $scope->scopedTraceQuery(...$conditions)
                ->select('span.exception.type', 'span.exception.message', 'span.exception.file', 'span.exception.line', 'span.user.id');

            foreach ($this->connections->traces()->search($traceQuery, $start, $end, min(200, $limit)) as $summary) {
                foreach ($summary->matchedSpans as $span) {
                    $type = $span->attributes['exception.type'] ?? null;

                    if (! is_scalar($type) || (string) $type === '') {
                        continue;
                    }

                    $group = $span->attributes['exception.group'] ?? null;
                    $group = is_scalar($group) && (string) $group !== ''
                        ? (string) $group
                        : ExceptionFingerprint::compute((string) $type, (string) ($span->attributes['exception.file'] ?? ''), (int) ($span->attributes['exception.line'] ?? 0));

                    $user = $span->attributes['user.id'] ?? '';

                    $occurrences[] = [
                        'group' => $group,
                        'type' => (string) $type,
                        'message' => (string) ($span->attributes['exception.message'] ?? ''),
                        'nano' => $span->startNano,
                        'traceId' => $summary->traceId,
                        'service' => $summary->rootServiceName,
                        'user' => is_scalar($user) ? (string) $user : '',
                        'frontend' => true,
                        'attributes' => [
                            'exception.type' => (string) $type,
                            'service.name' => $summary->rootServiceName,
                            'user.id' => is_scalar($user) ? (string) $user : '',
                            'source' => 'frontend',
                        ],
                    ];
                }
            }
        }

        $q = mb_strtolower(trim($scope->param('q')));

        if ($q !== '') {
            $occurrences = array_values(array_filter(
                $occurrences,
                static fn (array $o): bool => str_contains(mb_strtolower($o['type'].' '.$o['message']), $q),
            ));
        }

        return $occurrences;
    }

    /**
     * @return array<string, mixed>
     */
    public function explore(RequestScope $scope, int $limit): array
    {
        $occurrences = $this->occurrences($scope, $limit);
        [$start, $end] = $scope->range();
        $startMs = $start->getTimestamp() * 1000;
        $endMs = $end->getTimestamp() * 1000;

        $groups = [];
        $buckets = 24;
        $width = max(1, intdiv($endMs - $startMs, $buckets));

        foreach ($occurrences as $o) {
            $g = $groups[$o['group']] ?? [
                'group' => $o['group'], 'type' => $o['type'], 'message' => $o['message'],
                'count' => 0, 'users' => [], 'services' => [], 'frontend' => false, 'backend' => false,
                'firstMs' => PHP_INT_MAX, 'lastMs' => 0, 'traceId' => null, 'spark' => array_fill(0, $buckets, 0),
            ];

            $ms = intdiv($o['nano'], 1_000_000);
            $g['count']++;
            $o['frontend'] ? $g['frontend'] = true : $g['backend'] = true;

            if ($o['user'] !== '') {
                $g['users'][$o['user']] = true;
            }

            if ($o['service'] !== '') {
                $g['services'][$o['service']] = true;
            }

            $g['firstMs'] = min($g['firstMs'], $ms);

            if ($ms >= $g['lastMs']) {
                $g['lastMs'] = $ms;
                $g['traceId'] = $o['traceId'] ?? $g['traceId'];
                $g['type'] = $o['type'] !== '' ? $o['type'] : $g['type'];
                $g['message'] = $o['message'] !== '' ? $o['message'] : $g['message'];
            }

            $i = intdiv($ms - $startMs, $width);

            if ($i >= 0 && $i < $buckets) {
                $g['spark'][$i]++;
            }

            $groups[$o['group']] = $g;
        }

        $rows = array_map(static fn (array $g): array => [
            'group' => $g['group'],
            'type' => $g['type'],
            'message' => $g['message'],
            'count' => $g['count'],
            'users' => count($g['users']),
            'services' => array_keys($g['services']),
            'source' => $g['frontend'] && $g['backend'] ? 'full-stack' : ($g['frontend'] ? 'frontend' : 'backend'),
            'firstMs' => $g['firstMs'],
            'lastMs' => $g['lastMs'],
            'traceId' => $g['traceId'],
            'spark' => $g['spark'],
        ], array_values($groups));

        $sort = $scope->param('sort', 'count');

        usort($rows, match ($sort) {
            'last' => static fn (array $a, array $b): int => $b['lastMs'] <=> $a['lastMs'],
            'new' => static fn (array $a, array $b): int => $b['firstMs'] <=> $a['firstMs'],
            default => static fn (array $a, array $b): int => [$b['count'], $b['lastMs']] <=> [$a['count'], $a['lastMs']],
        });

        $asSpans = array_map(static fn (array $o): array => [
            'startMs' => intdiv($o['nano'], 1_000_000), 'durationMs' => 0.0, 'error' => true,
            'traceId' => $o['traceId'] ?? '', 'attributes' => $o['attributes'],
        ], $occurrences);

        return [
            'signal' => 'errors',
            'rows' => $rows,
            'stats' => [
                'count' => count($occurrences),
                'groups' => count($rows),
                'users' => count(array_unique(array_filter(array_map(static fn (array $o): string => $o['user'], $occurrences)))),
                'frontend' => count(array_filter($occurrences, static fn (array $o): bool => $o['frontend'])),
                'perMinute' => count($occurrences) / max(1, $scope->rangeSeconds() / 60),
            ],
            'series' => Stats::series($asSpans, $startMs, $endMs),
            'groupBy' => null,
            'groups' => null,
            'sample' => ['size' => count($occurrences), 'limit' => $limit, 'truncated' => count($occurrences) >= $limit, 'exact' => false, 'groupsExact' => false],
            'range' => ['start' => $startMs, 'end' => $endMs],
        ];
    }

    /**
     * @param  list<string>  $keys
     * @return array{facets: list<array{key: string, label: string, group: string|null, custom: bool, values: list<array{value: string, count: int}>}>, exact: bool, sample: int}
     */
    public function facets(RequestScope $scope, array $keys): array
    {
        $occurrences = $this->occurrences($scope);
        $bags = array_map(static fn (array $o): array => $o['attributes'], $occurrences);
        $labels = ['exception.type' => 'Exception', 'service.name' => 'Service', 'user.id' => 'User', 'source' => 'Source'];
        $keys = $keys !== [] ? $keys : array_keys($labels);

        $facets = [];

        foreach ($keys as $key) {
            $facets[] = [
                'key' => $key,
                'label' => $labels[$key] ?? $this->dimensions->resolve($key)->label,
                'group' => null,
                'custom' => false,
                'values' => Stats::topValues($bags, $key),
            ];
        }

        return ['facets' => $facets, 'exact' => false, 'sample' => count($occurrences)];
    }

    private static function label(string $key): string
    {
        return match ($key) {
            'exception.type' => 'exception_type',
            'service.name' => ScopeLabels::logs('service'),
            default => str_replace(['.', '-'], '_', $key),
        };
    }
}
