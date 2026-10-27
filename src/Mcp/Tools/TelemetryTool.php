<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Mcp\Tools;

use Cbox\TelemetryUi\Support\ScopeLock;
use DateTimeImmutable;
use Illuminate\Support\Facades\Gate;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

/**
 * Shared base for the telemetry MCP tools — read-only windows over the same
 * drivers the dashboard uses.
 */
abstract class TelemetryTool extends Tool
{
    /** Hard ceilings so an agent can't drive an unbounded/expensive query. */
    protected const MAX_MINUTES = 10_080; // 7 days

    protected const MAX_LIMIT = 500;

    /**
     * @return array{0: DateTimeImmutable, 1: DateTimeImmutable}
     */
    protected function window(Request $request, int $default = 60): array
    {
        $minutes = $this->minutes($request, $default);

        return [new DateTimeImmutable('-'.$minutes.' minutes'), new DateTimeImmutable];
    }

    protected function minutes(Request $request, int $default = 60): int
    {
        $raw = $request->get('minutes', $default);

        return min(self::MAX_MINUTES, max(1, is_numeric($raw) ? (int) $raw : $default));
    }

    protected function limit(Request $request, int $default = 20): int
    {
        $raw = $request->get('limit', $default);

        return min(self::MAX_LIMIT, max(1, is_numeric($raw) ? (int) $raw : $default));
    }

    protected function offset(Request $request): int
    {
        $raw = $request->get('offset', 0);

        return max(0, is_numeric($raw) ? (int) $raw : 0);
    }

    /**
     * Whether this call came over stdio, run by the operator who started the
     * server — rather than over HTTP, where the agent acts as a dashboard
     * user and gets that user's gates.
     */
    protected function local(): bool
    {
        return app()->runningInConsole();
    }

    /**
     * The refusal for a raw PromQL or LogQL query while a scope lock is
     * active, or null when there is no lock.
     *
     * A raw query names its own selectors, and nothing here rewrites PromQL or
     * LogQL with certainty (functions, binary operators, subqueries and
     * `on()`/`ignoring()` all reach series a naive matcher injection would
     * miss). So under a lock the query is not run at all, rather than run
     * "mostly scoped". The scoped tools still answer.
     */
    protected function refuseRawQueryUnderLock(string $language): ?Response
    {
        if (! app(ScopeLock::class)->restricted()) {
            return null;
        }

        return Response::error(
            "Raw {$language} is not available while a scope lock is active: the query cannot be confined to the services and environments you may see. "
            .'Use list_services, list_traces, search_traces, trace_summary or trace_context, which stay inside the lock.',
        );
    }

    /** The HTTP rule for exception detail (stacktraces, source), applied to MCP. */
    protected function mayReadExceptions(): bool
    {
        return $this->local() || Gate::allows('viewTelemetryUi', ['exceptions']);
    }

    protected function flag(Request $request, string $key): bool
    {
        return filter_var($request->get($key, false), FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Drop null, empty-string and empty-array values, recursively — an agent
     * pays for every key it reads, and an absent key says "none" for free.
     *
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    protected function compact(array $data): array
    {
        $out = [];

        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $value = $this->compact($value);
            }

            if ($value === null || $value === '' || $value === []) {
                continue;
            }

            $out[$key] = $value;
        }

        return array_is_list($data) ? array_values($out) : $out;
    }
}
