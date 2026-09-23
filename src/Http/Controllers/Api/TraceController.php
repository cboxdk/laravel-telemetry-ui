<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Http\Controllers\Api;

use Cbox\TelemetryUi\Analysis\RequestReport;
use Cbox\TelemetryUi\Analysis\SignalContext;
use Cbox\TelemetryUi\Analysis\TraceLogs;
use Cbox\TelemetryUi\Analysis\TraceProfile;
use Cbox\TelemetryUi\Connectors\ConnectionManager;
use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Contracts\LocatesTracesInTime;
use Cbox\TelemetryUi\Contracts\TracesSource;
use Cbox\TelemetryUi\Explore\TraceExceptions;
use Cbox\TelemetryUi\Http\Api\ApiError;
use Cbox\TelemetryUi\Http\Api\Json;
use Cbox\TelemetryUi\Http\Api\Serializer;
use Cbox\TelemetryUi\Queries\Results\Trace;
use Cbox\TelemetryUi\Support\ScopeLabels;
use Cbox\TelemetryUi\Support\ScopeLock;
use Cbox\TelemetryUi\Support\TraceView;
use DateTimeImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * `GET /api/v2/traces/{id}` — the whole trace story in one payload: the
 * waterfall, the infra chain, per-service identities and the correlation the
 * v1 drawer showed (surrounding metrics vs baseline, the trace's logs, the
 * profile, and the request report).
 */
final class TraceController
{
    public function __invoke(
        ConnectionManager $connections,
        SignalContext $context,
        TraceProfile $profile,
        TraceLogs $traceLogs,
        TraceExceptions $exceptions,
        Request $request,
        string $traceId,
    ): JsonResponse {
        if (! Gate::allows('viewTelemetryUi', ['traces'])) {
            return ApiError::forbidden();
        }

        try {
            $trace = self::fetch($connections->traces(), $traceId, $request);
        } catch (SourceException $exception) {
            return ApiError::backend($exception->getMessage());
        }

        if ($trace->spans === []) {
            return ApiError::notFound("Trace {$traceId} was not found (it may have expired or been sampled away).");
        }

        // A trace id is a deep link anyone can paste: a locked viewer must not
        // read one belonging to a service outside their lock. Search and
        // Explore are already constrained; this is the one route that takes an
        // id straight from the URL.
        if (! self::withinLock($trace, app(ScopeLock::class))) {
            return ApiError::notFound("Trace {$traceId} was not found (it may have expired or been sampled away).");
        }

        $root = $trace->root();

        $logs = self::safe(static fn (): array => $traceLogs->forTrace($trace));
        $logsMatch = $logs !== [] ? 'trace' : null;

        if ($logs === []) {
            $logs = $exceptions->logsByWindow($trace);
            $logsMatch = $logs !== [] ? 'time' : null;
        }

        return Json::ok([
            'traceId' => $trace->traceId,
            'root' => $root !== null ? Serializer::span($root) : null,
            'durationMs' => round($trace->durationMs(), 3),
            'error' => $trace->hasError(),
            'spanCount' => count($trace->spans),
            'services' => array_map(Serializer::attributes(...), $trace->services),
            'waterfall' => array_map(static fn (array $row): array => [
                'span' => Serializer::span($row['span']),
                'depth' => $row['depth'],
                'offsetPct' => round($row['offsetPct'], 4),
                'widthPct' => round($row['widthPct'], 4),
                'ancestors' => $row['ancestors'],
                'children' => $row['children'],
            ], TraceView::waterfall($trace)),
            'chain' => array_map(static fn (array $hop): array => [
                'spanId' => $hop['span']->spanId,
                'service' => $hop['span']->serviceName,
                'name' => $hop['span']->name,
                'durationMs' => round($hop['span']->durationMs(), 3),
                'kind' => $hop['kind'],
                'color' => $hop['color'],
            ], TraceView::chain($trace)),
            'identities' => TraceView::identities($trace),
            'context' => array_map(Serializer::metricSummary(...), self::safe(static fn (): array => $context->forTrace($trace))),
            'profile' => self::safe(static fn (): array => $profile->forTrace($trace)),
            'report' => RequestReport::from($trace),
            'logs' => $logs,
            // 'trace' = joined on trace id; 'time' = the service's lines in the
            // root span's window (records without trace context).
            'logsMatch' => $logsMatch,
            'exceptions' => $exceptions->forTrace($trace),
            'dimensionLinks' => Serializer::dimensionLinks($trace),
        ]);
    }

    /**
     * The trace, found fast when the caller says when it happened: `?at=` is
     * the request's start in epoch milliseconds, known to every list the trace
     * was opened from. A backend that can search by time (Tempo) is then asked
     * about the blocks within `traces.lookup_window` of it only — measured on a
     * busy Tempo, a median 0.9 s against 2.1 s for a lookup across retention.
     * A trace the window misses (a wrong or stale `at`) falls back to the full
     * lookup, so the hint can only ever make it faster.
     */
    private static function fetch(TracesSource $source, string $traceId, Request $request): Trace
    {
        $at = $request->query('at');
        $window = max(60, (int) config('telemetry-ui.traces.lookup_window', 3600));

        if ($source instanceof LocatesTracesInTime && is_string($at) && ctype_digit($at) && strlen($at) <= 15) {
            $seconds = intdiv((int) $at, 1000);
            $trace = $source->traceBetween(
                $traceId,
                new DateTimeImmutable('@'.($seconds - $window)),
                new DateTimeImmutable('@'.($seconds + $window)),
            );

            if ($trace->spans !== []) {
                return $trace;
            }
        }

        return $source->trace($traceId);
    }

    /**
     * Correlation is best-effort: a metrics or logs backend being down must
     * not take the waterfall with it.
     *
     * @template T of array<mixed>
     *
     * @param  callable(): T  $fn
     * @return T|array{}
     */
    private static function safe(callable $fn): array
    {
        try {
            return $fn();
        } catch (SourceException) {
            return [];
        }
    }

    /**
     * Whether every service the trace touches is one this viewer may read, and
     * ran in an environment they may read. A trace that crosses out of the lock
     * is treated as absent, not forbidden: its existence is itself information.
     *
     * The environment comes from each service's resource attributes, under the
     * configured trace attribute (see {@see ScopeLabels}). Locked to
     * environments, a service that does not say which one it ran in is outside
     * the lock: an unlabelled span could be from anywhere.
     */
    private static function withinLock(Trace $trace, ScopeLock $lock): bool
    {
        if ($lock->servicesLocked()) {
            foreach (array_keys($trace->services) as $service) {
                if (! in_array((string) $service, $lock->services(), true)) {
                    return false;
                }
            }
        }

        if ($lock->environmentsLocked()) {
            $key = ScopeLabels::traceResourceKey('environment');

            if ($trace->services === []) {
                return false;
            }

            foreach ($trace->services as $attributes) {
                $environment = $attributes[$key] ?? null;

                if (! is_string($environment) || ! in_array($environment, $lock->environments(), true)) {
                    return false;
                }
            }
        }

        return true;
    }
}
