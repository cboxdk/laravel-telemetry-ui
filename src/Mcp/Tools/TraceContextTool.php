<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Mcp\Tools;

use Cbox\TelemetryUi\Analysis\SignalContext;
use Cbox\TelemetryUi\Connectors\ConnectionManager;
use Cbox\TelemetryUi\Connectors\SourceException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\JsonSchema as JsonSchemaFactory;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

final class TraceContextTool extends Tool
{
    protected string $name = 'trace_context';

    protected string $description = 'Fetch a trace plus the host/runtime signals around it and how they compare to normal — the "what happened / what was different" tool for incident RCA.';

    public function __construct(
        private readonly ConnectionManager $connections,
        private readonly SignalContext $context,
    ) {}

    /**
     * @return array<string, JsonSchemaFactory>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'trace_id' => JsonSchemaFactory::string()->description('The trace id (hex)')->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        try {
            $trace = $this->connections->traces()->trace((string) $request->get('trace_id', ''));
        } catch (SourceException $exception) {
            return Response::error($exception->getMessage());
        }

        $context = [];
        foreach ($this->context->forTrace($trace) as $signal) {
            $context[] = [
                'label' => $signal->label,
                'unit' => $signal->unit,
                'current' => $signal->current,
                'baseline' => $signal->baseline,
                'outlier' => $signal->isOutlier(),
            ];
        }

        return Response::json([
            'trace_id' => $trace->traceId,
            'duration_ms' => $trace->durationMs(),
            'spans' => count($trace->spans),
            'has_error' => $trace->hasError(),
            'context' => $context,
        ]);
    }
}
