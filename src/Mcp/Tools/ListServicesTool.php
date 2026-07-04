<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Mcp\Tools;

use Cbox\TelemetryUi\Support\Fleet;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

final class ListServicesTool extends Tool
{
    protected string $name = 'list_services';

    protected string $description = 'List the services and environments currently reporting telemetry.';

    public function __construct(private readonly Fleet $fleet) {}

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    public function handle(Request $request): Response
    {
        return Response::json([
            'services' => $this->fleet->services(),
            'environments' => $this->fleet->environments(),
        ]);
    }
}
