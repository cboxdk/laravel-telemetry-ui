<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Mcp\Tools;

use Cbox\TelemetryUi\Connectors\ConnectionManager;
use Cbox\TelemetryUi\Connectors\SourceException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\JsonSchema as JsonSchemaFactory;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;

final class QueryRangeTool extends TelemetryTool
{
    protected string $name = 'query_range';

    protected string $description = 'Run a range PromQL query; returns each series summarized (last/min/max).';

    public function __construct(private readonly ConnectionManager $connections) {}

    /**
     * @return array<string, JsonSchemaFactory>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => JsonSchemaFactory::string()->description('PromQL expression')->required(),
            'minutes' => JsonSchemaFactory::integer()->description('Lookback window in minutes')->default(60),
        ];
    }

    public function handle(Request $request): Response
    {
        [$start, $end] = $this->window($request);

        try {
            $rows = [];
            foreach ($this->connections->metrics()->queryRange((string) $request->get('query', ''), $start, $end) as $series) {
                $values = array_map(static fn ($point): float => $point->value, $series->points);
                $rows[] = [
                    'labels' => $series->labels,
                    'points' => count($values),
                    'last' => $values === [] ? null : $values[count($values) - 1],
                    'min' => $values === [] ? null : min($values),
                    'max' => $values === [] ? null : max($values),
                ];
            }
        } catch (SourceException $exception) {
            return Response::error($exception->getMessage());
        }

        return Response::json($rows);
    }
}
