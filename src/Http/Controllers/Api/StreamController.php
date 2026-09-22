<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Http\Controllers\Api;

use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Explore\LogExplorer;
use Cbox\TelemetryUi\Explore\SpanExplorer;
use Cbox\TelemetryUi\Http\Api\ApiError;
use Cbox\TelemetryUi\Http\Api\Json;
use Cbox\TelemetryUi\Http\Api\RequestScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * `GET /api/v2/stream/{logs|requests}` — live tail over Server-Sent Events.
 *
 * The backends are pull-only, so the stream polls them on the server (one
 * query every `interval` seconds for rows newer than the cursor) and pushes
 * `event: rows` batches. Each connection lives for a bounded window and then
 * ends; EventSource reconnects on its own and resumes from `Last-Event-ID`,
 * so a long tail never pins a PHP worker indefinitely. `?once=1` emits one
 * batch and closes (tests, and the client's polling fallback).
 */
final class StreamController
{
    public function __invoke(Request $request, SpanExplorer $spans, LogExplorer $logs, string $signal): StreamedResponse|JsonResponse
    {
        if (! Gate::allows('viewTelemetryUi', [$signal === 'logs' ? 'logs' : 'requests'])) {
            return ApiError::forbidden();
        }

        $scope = RequestScope::fromRequest($request);
        $once = $request->boolean('once');
        $interval = max(1, (int) config('telemetry-ui.stream.interval', 2));
        $window = max(1, (int) config('telemetry-ui.stream.window', 25));

        $header = $request->header('Last-Event-ID');
        $cursor = is_string($header) && ctype_digit($header) ? $header : (string) $request->query('since', '');
        $cursor = ctype_digit($cursor) ? $cursor : (string) (time() * 1_000_000_000);

        return new StreamedResponse(function () use ($scope, $signal, $spans, $logs, $once, $interval, $window, $cursor): void {
            $deadline = time() + $window;

            echo "retry: 2000\n\n";
            $this->flush();

            do {
                try {
                    [$rows, $cursor] = $this->poll($scope, $signal, $spans, $logs, $cursor);
                } catch (SourceException $exception) {
                    echo 'event: error'."\n".'data: '.json_encode(['type' => 'backend', 'message' => $exception->getMessage()])."\n\n";
                    $this->flush();

                    return;
                }

                if ($rows !== []) {
                    echo 'id: '.$cursor."\n".'event: rows'."\n".'data: '.json_encode(Json::clean(['rows' => $rows]), Json::FLAGS)."\n\n";
                } else {
                    echo ": keepalive\n\n";
                }

                $this->flush();

                if ($once || connection_aborted() === 1) {
                    break;
                }

                sleep($interval);
            } while (time() < $deadline);
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache, no-store',
            'X-Accel-Buffering' => 'no',
            'Connection' => 'keep-alive',
        ]);
    }

    /**
     * Rows newer than the cursor (nanoseconds), newest first, and the new cursor.
     *
     * @return array{list<array<string, mixed>>, string}
     */
    private function poll(RequestScope $scope, string $signal, SpanExplorer $spans, LogExplorer $logs, string $cursor): array
    {
        $sinceNano = (int) $cursor;

        if ($signal === 'logs') {
            $rows = $logs->rows($scope, 200, $sinceNano);

            return [$rows, $rows !== [] ? $rows[0]['nano'] : $cursor];
        }

        $window = clone $scope;
        $window->from = (string) max(0, intdiv($sinceNano, 1_000_000_000) - 1);
        $window->to = (string) (time() + 1);

        $sinceMs = intdiv($sinceNano, 1_000_000);
        $rows = array_values(array_filter(
            $spans->rows($window, 'requests', 100),
            static fn (array $row): bool => $row['startMs'] > $sinceMs,
        ));

        return [$rows, $rows !== [] ? (string) ($rows[0]['startMs'] * 1_000_000) : $cursor];
    }

    private function flush(): void
    {
        if (ob_get_level() > 0) {
            @ob_flush();
        }

        flush();
    }
}
