<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Mcp\Tools;

use Cbox\TelemetryUi\Analysis\TraceDiagnostics;
use Cbox\TelemetryUi\Connectors\ConnectionManager;
use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Contracts\TracesSource;
use Cbox\TelemetryUi\Queries\Ir\TraceCondition;
use Cbox\TelemetryUi\Queries\Ir\TraceOp;
use Cbox\TelemetryUi\Queries\Ir\TraceQuery;
use Cbox\TelemetryUi\Queries\Results\TraceSummary;
use DateTimeImmutable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\JsonSchema as JsonSchemaFactory;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;

/**
 * Recent traces (root spans), optionally only those with a label or with
 * something worth a look. The hints come from the root span's tallies plus
 * one bounded search for traces with an error span — and, only for roots
 * from before laravel-telemetry 3.2 (no `db.query.slow.count`), one for
 * traces with a slow query. A hint list is cheap and indicative;
 * `trace_summary` is the full diagnosis.
 */
final class ListTracesTool extends TelemetryTool
{
    protected string $name = 'list_traces';

    protected string $description = 'List recent traces (newest first), optionally filtered by label (the X-Telemetry-Label request header) and/or to those with flags. Each row has a "flags" hint list (error, 5xx, n_plus_one, duplicate, slow_query, overhead) — pass a trace_id to trace_summary for the full diagnosis.';

    /** Root-span tallies selected for the hints. */
    private const SELECT = [
        'span.laravel.trace.label',
        'span.http.response.status_code',
        'span.db.query.count',
        'span.db.query.n_plus_one.count',
        'span.db.query.duplicate.count',
        'span.db.query.slow.count',
        'span.telemetry.overhead_ms',
    ];

    public function __construct(
        private readonly ConnectionManager $connections,
        private readonly TraceDiagnostics $diagnostics,
    ) {}

    /**
     * @return array<string, JsonSchemaFactory>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'label' => JsonSchemaFactory::string()->description('Only traces with this label'),
            'has_flags' => JsonSchemaFactory::boolean()->description('Only traces with at least one flag hint')->default(false),
            'service' => JsonSchemaFactory::string()->description('Only this service.name'),
            'minutes' => JsonSchemaFactory::integer()->description('Lookback window in minutes')->default(60),
            'limit' => JsonSchemaFactory::integer()->description('Max traces per page')->default(20),
            'offset' => JsonSchemaFactory::integer()->description('Traces to skip (from next_offset)')->default(0),
        ];
    }

    public function handle(Request $request): Response
    {
        [$start, $end] = $this->window($request);
        $limit = $this->limit($request);
        $offset = $this->offset($request);
        $label = trim((string) $request->get('label', ''));
        $service = trim((string) $request->get('service', ''));
        $hasFlags = $this->flag($request, 'has_flags');

        $scope = $service !== '' ? [TraceCondition::eq('resource.service.name', $service)] : [];
        // Root spans only: they hold the tallies the hints read. From
        // laravel-telemetry 3.2 the label is on every span of the request (and
        // its jobs), so it narrows the roots rather than finding them.
        $conditions = [...$scope, TraceCondition::token('nestedSetParent', TraceOp::Lt, '0')];

        if ($label !== '') {
            $conditions[] = TraceCondition::eq('span.'.TraceDiagnostics::LABEL_ATTRIBUTE, $label);
        }

        // Filtering after the fetch thins the page, so fetch a wider sample.
        $fetch = min(self::MAX_LIMIT, ($offset + $limit) * ($hasFlags ? 4 : 1) + 1);

        try {
            $source = $this->connections->traces();
            $summaries = $source->search(new TraceQuery($conditions, self::SELECT), $start, $end, $fetch);
        } catch (SourceException $exception) {
            return Response::error($exception->getMessage());
        }

        $thresholds = $this->diagnostics->thresholds();
        $errors = $this->traceIds($source, [...$scope, TraceCondition::token('status', TraceOp::Eq, 'error')], $start, $end);

        // Roots that count their slow queries answer for themselves; only
        // older ones need the search.
        $legacy = array_filter($summaries, static fn (TraceSummary $s): bool => ! isset($s->matchedSpans[0]->attributes['db.query.slow.count']));
        $slow = $legacy === [] ? [] : $this->traceIds($source, [
            ...$scope,
            TraceCondition::nil('span.db.query.text'),
            TraceCondition::token('duration', TraceOp::Gte, ((int) $thresholds['slow_query_ms']).'ms'),
        ], $start, $end);

        usort($summaries, static fn (TraceSummary $x, TraceSummary $y): int => $y->startedAt <=> $x->startedAt);

        $rows = [];

        foreach ($summaries as $summary) {
            $attributes = isset($summary->matchedSpans[0]) ? $summary->matchedSpans[0]->attributes : [];
            $num = static fn (string $key): float => is_numeric($attributes[$key] ?? null) ? (float) $attributes[$key] : 0.0;
            $overhead = $num('telemetry.overhead_ms');

            $hints = array_keys(array_filter([
                'error' => isset($errors[$summary->traceId]),
                '5xx' => $num('http.response.status_code') >= 500,
                'n_plus_one' => $num('db.query.n_plus_one.count') > 0,
                'duplicate' => $num('db.query.duplicate.count') > 0,
                'slow_query' => isset($attributes['db.query.slow.count']) ? $num('db.query.slow.count') > 0 : isset($slow[$summary->traceId]),
                'overhead' => $overhead >= $thresholds['overhead_ms'] && $summary->durationMs > 0.0
                    && $overhead / $summary->durationMs >= $thresholds['overhead_ratio'],
            ]));

            if ($hasFlags && $hints === []) {
                continue;
            }

            $rows[] = [
                'trace_id' => $summary->traceId,
                'name' => $summary->rootTraceName,
                'service' => $summary->rootServiceName,
                'duration_ms' => round($summary->durationMs, 1),
                'started_at' => $summary->startedAt->format('Y-m-d\TH:i:s\Z'),
                'label' => is_scalar($attributes['laravel.trace.label'] ?? null) ? (string) $attributes['laravel.trace.label'] : null,
                'queries' => isset($attributes['db.query.count']) ? (int) $num('db.query.count') : null,
                'flags' => $hints,
            ];
        }

        $page = array_slice($rows, $offset, $limit);

        return Response::json($this->compact([
            'traces' => $page,
            'next_offset' => count($rows) > $offset + $limit ? $offset + $limit : null,
            'note' => $page === [] ? 'No matching traces in the last '.$this->minutes($request).' minutes.' : null,
        ]));
    }

    /**
     * Trace ids matching a spanset, as a set. Fail-open: a hint the backend
     * cannot answer is a hint left off, not a failed listing.
     *
     * @param  list<TraceCondition>  $conditions
     * @return array<string, true>
     */
    private function traceIds(TracesSource $source, array $conditions, DateTimeImmutable $start, DateTimeImmutable $end): array
    {
        try {
            $ids = [];

            foreach ($source->search(new TraceQuery($conditions), $start, $end, self::MAX_LIMIT) as $summary) {
                $ids[$summary->traceId] = true;
            }

            return $ids;
        } catch (SourceException) {
            return [];
        }
    }
}
