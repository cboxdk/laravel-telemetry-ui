<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Mcp\Tools;

use Cbox\TelemetryUi\Connectors\ConnectionManager;
use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Queries\Ir\TraceQuery;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\JsonSchema as JsonSchemaFactory;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;

final class SearchTracesTool extends TelemetryTool
{
    protected string $name = 'search_traces';

    protected string $description = 'Search traces with TraceQL; returns matching trace summaries.';

    public function __construct(private readonly ConnectionManager $connections) {}

    /**
     * @return array<string, JsonSchemaFactory>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => JsonSchemaFactory::string()->description('TraceQL expression, e.g. { status = error }')->required(),
            'minutes' => JsonSchemaFactory::integer()->description('Lookback window in minutes')->default(60),
            'limit' => JsonSchemaFactory::integer()->description('Max traces')->default(20),
        ];
    }

    public function handle(Request $request): Response
    {
        [$start, $end] = $this->window($request);
        $limit = $this->limit($request);

        try {
            $rows = [];
            foreach ($this->connections->traces()->search(TraceQuery::raw((string) $request->get('query', '')), $start, $end, $limit) as $trace) {
                $rows[] = [
                    'trace_id' => $trace->traceId,
                    'service' => $trace->rootServiceName,
                    'name' => $trace->rootTraceName,
                    'duration_ms' => $trace->durationMs,
                    'started_at' => $trace->startedAt->format('c'),
                ];
            }
        } catch (SourceException $exception) {
            return Response::error($exception->getMessage());
        }

        return Response::json($rows);
    }
}
