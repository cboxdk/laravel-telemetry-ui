<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Mcp\Tools;

use Cbox\TelemetryUi\Connectors\ConnectionManager;
use Cbox\TelemetryUi\Connectors\SourceException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\JsonSchema as JsonSchemaFactory;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

final class QueryMetricsTool extends Tool
{
    protected string $name = 'query_metrics';

    protected string $description = 'Run an instant PromQL query and return the resulting samples.';

    public function __construct(private readonly ConnectionManager $connections) {}

    /**
     * @return array<string, JsonSchemaFactory>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => JsonSchemaFactory::string()->description('PromQL expression')->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        try {
            $rows = [];
            foreach ($this->connections->metrics()->query((string) $request->get('query', '')) as $sample) {
                $rows[] = ['labels' => $sample->labels, 'value' => $sample->value];
            }
        } catch (SourceException $exception) {
            return Response::error($exception->getMessage());
        }

        return Response::json($rows);
    }
}
