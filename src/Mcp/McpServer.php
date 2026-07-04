<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Mcp;

use Cbox\TelemetryUi\Analysis\SignalContext;
use Cbox\TelemetryUi\Connectors\ConnectionManager;
use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Support\Fleet;
use DateTimeImmutable;
use Throwable;

/**
 * A minimal, dependency-free MCP server (JSON-RPC 2.0, newline-delimited stdio
 * transport) that exposes the read side of the stack — metrics, traces, logs
 * and the correlation/analysis layer — as tools an external agent can call.
 * This is the ecosystem seam: the same analysis a card renders, a Claude or
 * Cursor session can query for incident RCA.
 *
 * `handle()` is pure (message in, message out) so the protocol is testable
 * without touching stdio; McpCommand runs the read/write loop around it.
 */
final class McpServer
{
    private const PROTOCOL = '2025-06-18';

    public function __construct(
        private readonly ConnectionManager $connections,
        private readonly Fleet $fleet,
        private readonly SignalContext $context,
    ) {}

    /**
     * @param  array<string, mixed>  $message  a decoded JSON-RPC message
     * @return array<string, mixed>|null the response, or null for notifications
     */
    public function handle(array $message): ?array
    {
        $id = $message['id'] ?? null;
        $method = is_string($message['method'] ?? null) ? $message['method'] : '';

        // Notifications (no id) are fire-and-forget.
        if ($id === null) {
            return null;
        }

        try {
            return match ($method) {
                'initialize' => $this->ok($id, [
                    'protocolVersion' => self::PROTOCOL,
                    'capabilities' => ['tools' => (object) []],
                    'serverInfo' => ['name' => 'telemetry-ui', 'version' => '0.1.0'],
                ]),
                'ping' => $this->ok($id, (object) []),
                'tools/list' => $this->ok($id, ['tools' => $this->toolSpecs()]),
                'tools/call' => $this->ok($id, $this->callTool($message['params'] ?? [])),
                default => $this->error($id, -32601, "Method not found: {$method}"),
            };
        } catch (Throwable $exception) {
            return $this->error($id, -32603, $exception->getMessage());
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function toolSpecs(): array
    {
        $minutes = ['type' => 'integer', 'description' => 'Lookback window in minutes', 'default' => 60];
        $limit = ['type' => 'integer', 'description' => 'Max rows', 'default' => 20];

        return [
            $this->spec('list_services', 'List the services and environments reporting telemetry.', []),
            $this->spec('query_metrics', 'Run an instant PromQL query and return the resulting samples.', [
                'query' => ['type' => 'string', 'description' => 'PromQL expression'],
            ], ['query']),
            $this->spec('query_range', 'Run a range PromQL query; returns each series summarized (last/min/max).', [
                'query' => ['type' => 'string', 'description' => 'PromQL expression'],
                'minutes' => $minutes,
            ], ['query']),
            $this->spec('search_traces', 'Search traces with TraceQL; returns matching trace summaries.', [
                'query' => ['type' => 'string', 'description' => 'TraceQL expression, e.g. { status = error }'],
                'minutes' => $minutes,
                'limit' => $limit,
            ], ['query']),
            $this->spec('query_logs', 'Query logs with LogQL; returns matching log lines.', [
                'query' => ['type' => 'string', 'description' => 'LogQL expression, e.g. {service_name="web"}'],
                'minutes' => $minutes,
                'limit' => $limit,
            ], ['query']),
            $this->spec('trace_context', 'Fetch a trace plus the host/runtime signals around it and how they compare to normal — the "what happened / what was different" tool for incident RCA.', [
                'trace_id' => ['type' => 'string', 'description' => 'The trace id (hex)'],
            ], ['trace_id']),
        ];
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function callTool(array $params): array
    {
        $name = is_string($params['name'] ?? null) ? $params['name'] : '';
        /** @var array<string, mixed> $args */
        $args = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];

        try {
            $text = match ($name) {
                'list_services' => $this->listServices(),
                'query_metrics' => $this->queryMetrics($args),
                'query_range' => $this->queryRange($args),
                'search_traces' => $this->searchTraces($args),
                'query_logs' => $this->queryLogs($args),
                'trace_context' => $this->traceContext($args),
                default => throw new SourceException("Unknown tool: {$name}"),
            };
        } catch (SourceException $exception) {
            return ['content' => [['type' => 'text', 'text' => 'Error: '.$exception->getMessage()]], 'isError' => true];
        }

        return ['content' => [['type' => 'text', 'text' => $text]], 'isError' => false];
    }

    private function listServices(): string
    {
        return $this->json([
            'services' => $this->fleet->services(),
            'environments' => $this->fleet->environments(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function queryMetrics(array $args): string
    {
        $rows = [];
        foreach ($this->connections->metrics()->query($this->str($args, 'query')) as $sample) {
            $rows[] = ['labels' => $sample->labels, 'value' => $sample->value];
        }

        return $this->json($rows);
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function queryRange(array $args): string
    {
        [$start, $end] = $this->window($args);

        $rows = [];
        foreach ($this->connections->metrics()->queryRange($this->str($args, 'query'), $start, $end) as $series) {
            $values = array_map(static fn ($p): float => $p->value, $series->points);
            $rows[] = [
                'labels' => $series->labels,
                'points' => count($values),
                'last' => $values === [] ? null : $values[count($values) - 1],
                'min' => $values === [] ? null : min($values),
                'max' => $values === [] ? null : max($values),
            ];
        }

        return $this->json($rows);
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function searchTraces(array $args): string
    {
        [$start, $end] = $this->window($args);

        $rows = [];
        foreach ($this->connections->traces()->search($this->str($args, 'query'), $start, $end, $this->int($args, 'limit', 20)) as $t) {
            $rows[] = [
                'trace_id' => $t->traceId,
                'service' => $t->rootServiceName,
                'name' => $t->rootTraceName,
                'duration_ms' => $t->durationMs,
                'started_at' => $t->startedAt->format('c'),
            ];
        }

        return $this->json($rows);
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function queryLogs(array $args): string
    {
        [$start, $end] = $this->window($args);

        $rows = [];
        foreach ($this->connections->logs()->query($this->str($args, 'query'), $start, $end, $this->int($args, 'limit', 20)) as $entry) {
            $rows[] = [
                'time' => $entry->timestamp()->format('c'),
                'line' => $entry->line,
                'labels' => $entry->labels,
            ];
        }

        return $this->json($rows);
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function traceContext(array $args): string
    {
        $trace = $this->connections->traces()->trace($this->str($args, 'trace_id'));

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

        return $this->json([
            'trace_id' => $trace->traceId,
            'duration_ms' => $trace->durationMs(),
            'spans' => count($trace->spans),
            'has_error' => $trace->hasError(),
            'context' => $context,
        ]);
    }

    /**
     * @param  array<string, mixed>  $properties
     * @param  list<string>  $required
     * @return array<string, mixed>
     */
    private function spec(string $name, string $description, array $properties, array $required = []): array
    {
        return [
            'name' => $name,
            'description' => $description,
            'inputSchema' => [
                'type' => 'object',
                'properties' => $properties === [] ? (object) [] : $properties,
                'required' => $required,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array{0: DateTimeImmutable, 1: DateTimeImmutable}
     */
    private function window(array $args): array
    {
        $minutes = max(1, $this->int($args, 'minutes', 60));

        return [new DateTimeImmutable('-'.$minutes.' minutes'), new DateTimeImmutable];
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function str(array $args, string $key): string
    {
        return is_string($args[$key] ?? null) ? $args[$key] : '';
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function int(array $args, string $key, int $default): int
    {
        return is_int($args[$key] ?? null) ? $args[$key] : $default;
    }

    private function json(mixed $value): string
    {
        return json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}';
    }

    /**
     * @return array<string, mixed>
     */
    private function ok(mixed $id, mixed $result): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
    }

    /**
     * @return array<string, mixed>
     */
    private function error(mixed $id, int $code, string $message): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]];
    }
}
