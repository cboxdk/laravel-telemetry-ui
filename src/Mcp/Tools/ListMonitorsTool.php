<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Mcp\Tools;

use Cbox\TelemetryUi\Monitors\Monitor;
use Cbox\TelemetryUi\Monitors\Monitors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\JsonSchema as JsonSchemaFactory;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * The active monitors (cboxdk/laravel-telemetry directives) on the services
 * this dashboard can reach. Traces they recorded carry `telemetry.directive`
 * = the monitor's id: search them with `{ span.telemetry.directive = "<id>" }`.
 */
#[IsReadOnly]
final class ListMonitorsTool extends TelemetryTool
{
    protected string $name = 'list_monitors';

    protected string $description = 'List active monitors (directives that keep every trace of one route, path, user, job or context value for a while, optionally with a CPU profile), per service, with what they match, when they expire, why and who placed them. Traces they recorded carry span attribute telemetry.directive = the monitor id.';

    public function __construct(private readonly Monitors $monitors) {}

    /**
     * @return array<string, JsonSchemaFactory>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'service' => JsonSchemaFactory::string()->description('Only this service'),
        ];
    }

    public function handle(Request $request): Response
    {
        if ($this->monitors->services() === []) {
            return Response::error('No monitorable service: this app does not run cboxdk/laravel-telemetry with directives, and telemetry-ui.monitors.services is empty.');
        }

        $service = trim((string) $request->get('service', ''));
        $list = $this->monitors->list($service === '' ? null : $service);
        $now = time();

        return Response::json($this->compact([
            'services' => array_column($this->monitors->services(), 'service'),
            'monitors' => array_map(static fn (Monitor $m): array => [
                'id' => $m->id,
                'service' => $m->service,
                'match' => $m->describe(),
                'profile' => $m->profile,
                'expires_in_minutes' => (int) ceil(($m->expiresAt - $now) / 60),
                'reason' => $m->reason,
                'created_by' => $m->createdBy,
            ], $list['monitors']),
            'errors' => $list['errors'],
        ]));
    }
}
