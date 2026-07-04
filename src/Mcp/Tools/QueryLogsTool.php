<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Mcp\Tools;

use Cbox\TelemetryUi\Connectors\ConnectionManager;
use Cbox\TelemetryUi\Connectors\SourceException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\JsonSchema as JsonSchemaFactory;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;

final class QueryLogsTool extends TelemetryTool
{
    protected string $name = 'query_logs';

    protected string $description = 'Query logs with LogQL; returns matching log lines.';

    public function __construct(private readonly ConnectionManager $connections) {}

    /**
     * @return array<string, JsonSchemaFactory>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => JsonSchemaFactory::string()->description('LogQL expression, e.g. {service_name="web"}')->required(),
            'minutes' => JsonSchemaFactory::integer()->description('Lookback window in minutes')->default(60),
            'limit' => JsonSchemaFactory::integer()->description('Max lines')->default(20),
        ];
    }

    public function handle(Request $request): Response
    {
        [$start, $end] = $this->window($request);
        $limit = $this->limit($request);

        try {
            $rows = [];
            foreach ($this->connections->logs()->query((string) $request->get('query', ''), $start, $end, $limit) as $entry) {
                $rows[] = [
                    'time' => $entry->timestamp()->format('c'),
                    'line' => $entry->line,
                    'labels' => $entry->labels,
                ];
            }
        } catch (SourceException $exception) {
            return Response::error($exception->getMessage());
        }

        return Response::json($rows);
    }
}
