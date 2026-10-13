<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Mcp\Tools;

use Cbox\TelemetryUi\Analysis\TraceDiagnosis;
use Cbox\TelemetryUi\Analysis\TraceDiagnostics;
use Cbox\TelemetryUi\Connectors\SourceException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\JsonSchema as JsonSchemaFactory;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;

/**
 * One trace, diagnosed: what it was, where the time went, its cost tallies
 * and what is wrong with it — errors first, then N+1s, identical repeats,
 * slow queries and telemetry overhead, each with the app-code line that ran
 * it when laravel-telemetry attributed one.
 */
final class TraceSummaryTool extends TelemetryTool
{
    protected string $name = 'trace_summary';

    protected string $description = 'Diagnose one trace: identity (root, service, status, duration, label), time by category (db/cache/http/view/queue/other), totals (queries, N+1, duplicates, telemetry overhead) and flags — errors first, then N+1 query shapes with their origin file:line, identical repeats, slow queries and high telemetry overhead. Paged; pass verbose for the full SQL, span ids and per-shape timings.';

    public function __construct(private readonly TraceDiagnostics $diagnostics) {}

    /**
     * @return array<string, JsonSchemaFactory>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'trace_id' => JsonSchemaFactory::string()->description('The trace id (hex)')->required(),
            'verbose' => JsonSchemaFactory::boolean()->description('Include full SQL, span ids and per-flag detail, and the query shapes')->default(false),
            'limit' => JsonSchemaFactory::integer()->description('Max flags per page')->default(25),
            'offset' => JsonSchemaFactory::integer()->description('Flags to skip (from next_offset)')->default(0),
        ];
    }

    public function handle(Request $request): Response
    {
        $traceId = trim((string) $request->get('trace_id', ''));

        if ($traceId === '') {
            return Response::error('trace_id is required.');
        }

        try {
            $diagnosis = $this->diagnostics->diagnose($traceId);
        } catch (SourceException $exception) {
            return Response::error($exception->getMessage());
        }

        if ($diagnosis->identity['spans'] === 0) {
            return Response::error("Trace {$traceId} has no spans (not found, or outside the trace store's retention).");
        }

        return Response::json($this->summary($diagnosis, $this->flag($request, 'verbose'), $this->offset($request), $this->limit($request, 25)));
    }

    /**
     * @return array<array-key, mixed>
     */
    private function summary(TraceDiagnosis $diagnosis, bool $verbose, int $offset, int $limit): array
    {
        $flags = array_map(static function (array $flag) use ($verbose): array {
            unset($flag['key']);

            if (! $verbose) {
                unset($flag['detail']);
            }

            return $flag;
        }, $diagnosis->flags);

        $page = array_slice($flags, $offset, $limit);
        $next = $offset + $limit < count($flags) ? $offset + $limit : null;

        $levels = array_count_values(array_column($diagnosis->flags, 'level'));

        $shapes = null;

        if ($verbose) {
            $shapes = array_map(static fn (array $s): array => [
                'shape' => str_starts_with($s['key'], 'text:') ? null : $s['key'],
                'query' => $s['text'],
                'count' => $s['count'],
                'total_ms' => round($s['totalMs'], 2),
                'max_ms' => round($s['maxMs'], 2),
                'repeat' => $s['repeat'],
                'origin' => $s['origin'],
            ], array_slice(array_values($diagnosis->shapes), 0, $limit));
        }

        return $this->compact([
            ...$diagnosis->identity,
            'timing_ms' => array_filter($diagnosis->timing, static fn (float $ms): bool => $ms > 0.0),
            'totals' => $diagnosis->totals,
            'flag_counts' => ['errors' => $levels['error'] ?? 0, 'warnings' => $levels['warning'] ?? 0],
            'flags' => $page,
            'flags_total' => count($flags),
            'next_offset' => $next,
            'shapes' => $shapes,
        ]);
    }
}
