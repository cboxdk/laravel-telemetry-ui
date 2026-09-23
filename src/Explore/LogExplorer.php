<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Explore;

use Cbox\TelemetryUi\Connectors\ConnectionManager;
use Cbox\TelemetryUi\Dimensions\Dimensions;
use Cbox\TelemetryUi\Http\Api\Filter;
use Cbox\TelemetryUi\Http\Api\RequestScope;
use Cbox\TelemetryUi\Queries\Ir\LabelFilter;
use Cbox\TelemetryUi\Queries\Ir\LabelMatcher;
use Cbox\TelemetryUi\Queries\Ir\LogQuery;
use Cbox\TelemetryUi\Queries\Ir\MatchOp;
use Cbox\TelemetryUi\Queries\Results\LogEntry;
use Cbox\TelemetryUi\Support\ScopeLabels;

/**
 * Explore over logs (LogQL through the IR). Dimension filters become pipeline
 * label filters on the snake_cased key (`billing.customer_id` →
 * `billing_customer_id`) — they match stream labels AND structured metadata,
 * which is where OTLP attributes land in Loki. Numeric comparisons the IR does
 * not express are applied read-side.
 *
 * @phpstan-type LogRow array{time: string, ms: int, nano: string, level: string, tone: string, service: string, message: string, traceId: string|null, labels: array<string, string>}
 */
final class LogExplorer
{
    public const DEFAULT_LIMIT = 500;

    private const HIDDEN = ['trace_id', 'span_id', 'level', 'detected_level', 'severity_number', 'severity_text', 'scope_name', 'observed_timestamp', 'flags'];

    public function __construct(
        private readonly ConnectionManager $connections,
        private readonly Dimensions $dimensions,
    ) {}

    public static function label(string $key): string
    {
        return str_replace(['.', '-'], '_', $key);
    }

    public function query(RequestScope $scope): LogQuery
    {
        $query = $scope->logQuery();

        foreach ($scope->where as $filter) {
            if ($filter->key === 'level') {
                // `level=error` matches either label; `level!=debug` must
                // match neither (De Morgan), not flip into a positive match.
                $pattern = '(?i)'.self::levelPattern($filter->value);
                $op = $filter->negated() ? MatchOp::Nre : MatchOp::Re;
                $query = $query->pipe(new LabelFilter([
                    new LabelMatcher('level', $op, $pattern),
                    new LabelMatcher('detected_level', $op, $pattern),
                ], or: ! $filter->negated()));

                continue;
            }

            $op = MatchOp::tryFrom($filter->op);

            if ($op === null) {
                continue;
            }

            // A derived dimension filters its source label, encoded — so the
            // backend still answers it (no read-side pass over the stream).
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

        $q = trim($scope->param('q'));

        return $q !== '' ? $query->lineContains($q) : $query;
    }

    /**
     * @return list<LogRow>
     */
    public function rows(RequestScope $scope, int $limit = self::DEFAULT_LIMIT, ?int $sinceNano = null): array
    {
        [$start, $end] = $scope->range();

        if ($sinceNano !== null) {
            $since = \DateTimeImmutable::createFromFormat('U.u', sprintf('%d.%06d', intdiv($sinceNano, 1_000_000_000), intdiv($sinceNano % 1_000_000_000, 1000)));
            $start = $since !== false && $since > $start ? $since : $start;
        }

        $entries = $this->connections->logs()->query($this->query($scope), $start, $end, $limit);

        $numeric = array_values(array_filter($scope->where, static fn (Filter $f): bool => in_array($f->op, ['>', '>=', '<', '<='], true)));

        $rows = [];

        foreach ($entries as $entry) {
            if ($sinceNano !== null && $entry->timestampNano <= $sinceNano) {
                continue;
            }

            foreach ($numeric as $filter) {
                if (! $filter->matches($entry->labels[self::label($filter->key)] ?? null)) {
                    continue 2;
                }
            }

            $rows[] = self::row($entry, $this->dimensions);
        }

        usort($rows, static fn (array $a, array $b): int => strcmp($b['nano'], $a['nano']) ?: 0);

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    public function explore(RequestScope $scope, int $limit, ?string $groupBy): array
    {
        $rows = $this->rows($scope, $limit);
        [$start, $end] = $scope->range();
        $startMs = $start->getTimestamp() * 1000;
        $endMs = $end->getTimestamp() * 1000;

        $levels = [];

        foreach ($rows as $row) {
            $levels[$row['level']] = ($levels[$row['level']] ?? 0) + 1;
        }

        arsort($levels);

        $asSpans = array_map(static fn (array $row): array => [
            'startMs' => $row['ms'],
            'durationMs' => 0.0,
            'error' => $row['tone'] === 'danger',
            'traceId' => $row['traceId'] ?? '',
            'attributes' => [...$row['labels'], 'level' => $row['level'], ScopeLabels::logs('service') => $row['service']],
        ], $rows);

        $errors = count(array_filter($rows, static fn (array $row): bool => $row['tone'] === 'danger'));

        return [
            'signal' => 'logs',
            'rows' => $rows,
            'stats' => [
                'count' => count($rows),
                'errors' => $errors,
                'errorRate' => $rows === [] ? 0.0 : $errors / count($rows),
                'levels' => $levels,
                'traces' => count(array_unique(array_filter(array_map(static fn (array $row): ?string => $row['traceId'], $rows)))),
                'perMinute' => count($rows) / max(1, $scope->rangeSeconds() / 60),
            ],
            'series' => Stats::series($asSpans, $startMs, $endMs),
            'groupBy' => $groupBy,
            'groups' => $groupBy !== null && $groupBy !== '' ? Stats::groupBy($asSpans, self::label($groupBy)) : null,
            'sample' => ['size' => count($rows), 'limit' => $limit, 'truncated' => count($rows) >= $limit, 'exact' => false, 'groupsExact' => false],
            'range' => ['start' => $startMs, 'end' => $endMs],
        ];
    }

    /**
     * @param  list<string>  $keys
     * @return array{facets: list<array{key: string, label: string, group: string|null, custom: bool, values: list<array{value: string, count: int}>}>, exact: bool, sample: int}
     */
    public function facets(RequestScope $scope, array $keys, int $limit = self::DEFAULT_LIMIT): array
    {
        $rows = $this->rows($scope, $limit);
        $bags = array_map(static fn (array $row): array => [...$row['labels'], 'level' => $row['level'], 'service.name' => $row['service']], $rows);

        if ($keys === []) {
            $keys = ['level', 'service.name'];

            foreach ($this->dimensions->all() as $dimension) {
                if (! $dimension->builtin) {
                    $keys[] = $dimension->key;
                }
            }
        }

        $facets = [];

        foreach (array_values(array_unique($keys)) as $key) {
            $dimension = $this->dimensions->resolve($key);
            $bagKey = in_array($key, ['level', 'service.name'], true) ? $key : self::label($key);

            $facets[] = [
                'key' => $key,
                'label' => $key === 'level' ? 'Level' : $dimension->label,
                'group' => $dimension->group,
                'custom' => ! $dimension->builtin && $this->dimensions->get($key) !== null,
                'values' => Stats::topValues($bags, $bagKey),
            ];
        }

        return ['facets' => $facets, 'exact' => false, 'sample' => count($rows)];
    }

    /**
     * @return LogRow
     */
    public static function row(LogEntry $entry, ?Dimensions $dimensions = null): array
    {
        $traceId = $entry->labels['trace_id'] ?? null;
        $level = strtolower($entry->labels['level'] ?? $entry->labels['detected_level'] ?? $entry->labels['severity_text'] ?? self::inferLevel($entry->line));

        $labels = [];

        foreach ($entry->labels as $key => $value) {
            if ($key !== ScopeLabels::logs('service') && ! in_array($key, self::HIDDEN, true)) {
                $labels[$key] = $value;
            }
        }

        // Dimensions derived from a label ride along as their own label, so
        // chips, facets and group-by see them like any other.
        foreach ($dimensions?->derived() ?? [] as $dimension) {
            $derived = $dimension->derived;

            if ($derived === null) {
                continue;
            }

            $source = $labels[self::label($derived->from)] ?? '';
            $value = $source !== '' ? $derived->extract($source) : null;

            if ($value !== null) {
                $labels[self::label($dimension->key)] = $value;
            }
        }

        ksort($labels);

        $ms = intdiv($entry->timestampNano, 1_000_000);

        return [
            'time' => $entry->timestamp()->format('Y-m-d\TH:i:s.vp'),
            'ms' => $ms,
            'nano' => (string) $entry->timestampNano,
            'level' => $level,
            'tone' => self::tone($level),
            'service' => $entry->labels[ScopeLabels::logs('service')] ?? '',
            'message' => $entry->line,
            'traceId' => is_string($traceId) && $traceId !== '' ? $traceId : null,
            'labels' => $labels,
        ];
    }

    public static function tone(string $level): string
    {
        return match (true) {
            in_array($level, ['error', 'err', 'critical', 'crit', 'alert', 'emergency', 'fatal'], true) => 'danger',
            in_array($level, ['warn', 'warning'], true) => 'warn',
            in_array($level, ['debug', 'trace'], true) => 'dim',
            default => 'info',
        };
    }

    private static function inferLevel(string $line): string
    {
        $line = strtolower(substr($line, 0, 200));

        return match (true) {
            str_contains($line, 'error') || str_contains($line, 'exception') || str_contains($line, 'critical') => 'error',
            str_contains($line, 'warn') => 'warning',
            str_contains($line, 'debug') => 'debug',
            default => 'info',
        };
    }

    private static function levelPattern(string $level): string
    {
        return match (strtolower($level)) {
            'error' => 'error|err|critical|alert|emergency|fatal',
            'warning', 'warn' => 'warn|warning',
            'debug' => 'debug|trace',
            default => preg_quote($level, '/'),
        };
    }
}
