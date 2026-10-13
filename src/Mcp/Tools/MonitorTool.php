<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Mcp\Tools;

use Cbox\TelemetryUi\Monitors\MonitorException;
use Cbox\TelemetryUi\Monitors\MonitorRequest;
use Cbox\TelemetryUi\Monitors\Monitors;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\JsonSchema as JsonSchemaFactory;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;

/**
 * Place (or remove) a monitor: for a while, keep every trace of one slice of
 * a service's traffic, with detail spans and optionally a CPU profile.
 *
 * A write tool, so it is only registered when `telemetry-ui.mcp.write` is on
 * (off by default), checks that again, and over HTTP also needs the
 * `manageTelemetryUi` ability. The viewer's scope lock applies exactly as
 * in the dashboard.
 */
final class MonitorTool extends TelemetryTool
{
    protected string $name = 'monitor';

    protected string $description = 'Monitor one slice of a service\'s traffic for a while: every matching trace is kept (whatever the sample rate), with detail spans and optionally a CPU profile, tagged telemetry.directive = <id>. match is route (name or URI pattern, * wildcards), path (no leading slash), user (user.id), context (key = a Telemetry::context() dimension such as tenant.id) or job (class pattern). Pass remove = <id> to stop one early. Use list_monitors to see active ones.';

    public function __construct(
        private readonly Monitors $monitors,
        private readonly Repository $config,
    ) {}

    /**
     * @return array<string, JsonSchemaFactory>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'service' => JsonSchemaFactory::string()->description('The service.name to monitor (see list_monitors for the monitorable ones)')->required(),
            'match' => JsonSchemaFactory::string()->enum(MonitorRequest::MATCHES)->description('What to match'),
            'value' => JsonSchemaFactory::string()->description('Route/path/job pattern, user id, or context value'),
            'key' => JsonSchemaFactory::string()->description('The context dimension, for match = context (e.g. tenant.id)'),
            'minutes' => JsonSchemaFactory::integer()->description('How long, 1–1440')->default(60),
            'profile' => JsonSchemaFactory::boolean()->description('Also capture a CPU profile')->default(false),
            'reason' => JsonSchemaFactory::string()->description('Why — shown to everyone who sees the monitor'),
            'remove' => JsonSchemaFactory::string()->description('Instead of placing one: the id of a monitor to remove'),
        ];
    }

    public function handle(Request $request): Response
    {
        if (! (bool) $this->config->get('telemetry-ui.mcp.write', false)) {
            return Response::error('MCP write tools are off. Set TELEMETRY_UI_MCP_WRITE=true to allow placing monitors.');
        }

        // stdio runs as the operator who started it; over HTTP the agent acts
        // as a dashboard user and needs the same ability the dashboard asks.
        if (! app()->runningInConsole() && ! Gate::allows('manageTelemetryUi')) {
            return Response::error('You are not authorized to place monitors (manageTelemetryUi).');
        }

        $service = trim((string) $request->get('service', ''));
        $remove = trim((string) $request->get('remove', ''));

        try {
            if ($remove !== '') {
                $this->monitors->remove($service, $remove);

                return Response::json(['removed' => $remove, 'service' => $service]);
            }

            $user = Auth::user();
            $monitor = $this->monitors->add($service, MonitorRequest::make(
                match: (string) $request->get('match', ''),
                key: is_string($request->get('key')) ? $request->get('key') : null,
                value: (string) $request->get('value', ''),
                minutes: $this->minutesFor($request),
                profile: $this->flag($request, 'profile'),
                reason: is_string($request->get('reason')) ? $request->get('reason') : null,
                createdBy: $user !== null ? Monitors::actor($user) : 'mcp',
            ));
        } catch (MonitorException $e) {
            return Response::error($e->getMessage());
        }

        return Response::json($this->compact([
            'id' => $monitor->id,
            'service' => $monitor->service,
            'match' => $monitor->describe(),
            'profile' => $monitor->profile,
            'expires_at' => gmdate('Y-m-d\TH:i:s\Z', $monitor->expiresAt),
            'traceql' => '{ span.telemetry.directive = "'.$monitor->id.'" }',
        ]));
    }

    private function minutesFor(Request $request): int
    {
        $raw = $request->get('minutes', 60);

        return is_numeric($raw) ? (int) $raw : 60;
    }
}
