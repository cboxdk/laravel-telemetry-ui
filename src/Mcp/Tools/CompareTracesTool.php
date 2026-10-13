<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Mcp\Tools;

use Cbox\TelemetryUi\Analysis\TraceComparison;
use Cbox\TelemetryUi\Analysis\TraceDiagnostics;
use Cbox\TelemetryUi\Connectors\SourceException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\JsonSchema as JsonSchemaFactory;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;

/**
 * Two traces side by side — before and after a fix, or a fast and a slow run
 * of one endpoint. Either side is a trace id, or a label (the
 * `X-Telemetry-Label` request header), which resolves to the newest trace
 * carrying it.
 */
final class CompareTracesTool extends TelemetryTool
{
    protected string $name = 'compare_traces';

    protected string $description = 'Compare two traces (before/after a fix): each side is a trace id or a label set with the X-Telemetry-Label request header (resolves to the newest trace with that label). Returns duration and totals deltas, time per category, query shapes that appeared/disappeared/changed count, and flags present on only one side.';

    public function __construct(private readonly TraceDiagnostics $diagnostics) {}

    /**
     * @return array<string, JsonSchemaFactory>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'a' => JsonSchemaFactory::string()->description('Baseline ("before"): trace id or label')->required(),
            'b' => JsonSchemaFactory::string()->description('Candidate ("after"): trace id or label')->required(),
            'minutes' => JsonSchemaFactory::integer()->description('How far back to look for a label')->default(1440),
            'verbose' => JsonSchemaFactory::boolean()->description('List every changed query shape and include full SQL')->default(false),
        ];
    }

    public function handle(Request $request): Response
    {
        [$start, $end] = $this->window($request, 1440);
        $verbose = $this->flag($request, 'verbose');

        try {
            $sides = [];

            foreach (['a', 'b'] as $side) {
                $ref = trim((string) $request->get($side, ''));

                if ($ref === '') {
                    return Response::error("{$side} is required: a trace id or a label.");
                }

                $traceId = preg_match('/^[0-9a-f]{16,32}$/i', $ref) === 1
                    ? $ref
                    : $this->diagnostics->latestWithLabel($ref, $start, $end);

                if ($traceId === null) {
                    return Response::error("No trace labelled \"{$ref}\" in the last {$this->minutes($request, 1440)} minutes.");
                }

                $diagnosis = $this->diagnostics->diagnose($traceId);

                if ($diagnosis->identity['spans'] === 0) {
                    return Response::error("Trace {$traceId} ({$side}) has no spans (not found, or outside retention).");
                }

                $sides[$side] = $diagnosis;
            }
        } catch (SourceException $exception) {
            return Response::error($exception->getMessage());
        }

        return Response::json($this->compact(TraceComparison::between($sides['a'], $sides['b'], $verbose)));
    }
}
