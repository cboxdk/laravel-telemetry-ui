<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;

/**
 * Parse an SSE body into its events.
 *
 * @return list<array{event: string, id: string|null, data: mixed}>
 */
function sseEvents(string $body): array
{
    $events = [];

    foreach (preg_split('/\n\n/', trim($body)) ?: [] as $block) {
        $event = ['event' => 'message', 'id' => null, 'data' => null];
        $data = null;

        foreach (explode("\n", $block) as $line) {
            if (str_starts_with($line, 'event: ')) {
                $event['event'] = substr($line, 7);
            } elseif (str_starts_with($line, 'id: ')) {
                $event['id'] = substr($line, 4);
            } elseif (str_starts_with($line, 'data: ')) {
                $data = substr($line, 6);
            }
        }

        if ($data !== null) {
            $event['data'] = json_decode($data, true);
            $events[] = $event;
        }
    }

    return $events;
}

it('streams new log lines as one event: rows batch and closes with once=1', function (): void {
    $now = time();
    $since = ($now - 60) * 1_000_000_000;

    Http::fake(['loki.test:3100/*' => Http::response(lokiStreams([
        ['stream' => ['service_name' => 'shop', 'level' => 'error'], 'values' => [
            [(string) (($now - 10) * 1_000_000_000), 'fresh failure'],
            [(string) (($now - 20) * 1_000_000_000), 'earlier failure'],
            // At or before the cursor: already delivered, never repeated.
            [(string) $since, 'old news'],
        ]],
    ]))]);

    $response = $this->get(apiUrl('stream/logs', ['once' => 1, 'since' => (string) $since, 'service' => 'shop']))
        ->assertOk()
        ->assertHeader('Content-Type', 'text/event-stream; charset=UTF-8')
        ->assertHeader('X-Accel-Buffering', 'no');

    $body = $response->streamedContent();

    expect($body)->toStartWith("retry: 2000\n\n")->toContain("event: rows\n");

    $events = sseEvents($body);

    expect($events)->toHaveCount(1)
        ->and($events[0]['event'])->toBe('rows')
        // The id is the newest row's nanosecond cursor, for Last-Event-ID.
        ->and($events[0]['id'])->toBe((string) (($now - 10) * 1_000_000_000))
        ->and(array_column($events[0]['data']['rows'], 'message'))->toBe(['fresh failure', 'earlier failure']);

    // The query itself starts at the cursor, not at the top of the period.
    Http::assertSent(fn ($request): bool => str_contains($request->url(), 'query_range')
        && (int) requestQuery($request)['start'] >= $since - 1_000_000_000
        && str_contains(requestQuery($request)['query'], 'service_name="shop"'));
});

it('resumes from Last-Event-ID over the since parameter', function (): void {
    $now = time();

    Http::fake(['loki.test:3100/*' => Http::response(lokiStreams([
        ['stream' => ['service_name' => 'shop', 'level' => 'info'], 'values' => [
            [(string) (($now - 10) * 1_000_000_000), 'after the reconnect'],
            [(string) (($now - 30) * 1_000_000_000), 'before the reconnect'],
        ]],
    ]))]);

    $body = $this->withHeaders(['Last-Event-ID' => (string) (($now - 20) * 1_000_000_000)])
        ->get(apiUrl('stream/logs', ['once' => 1, 'since' => (string) (($now - 60) * 1_000_000_000)]))
        ->assertOk()
        ->streamedContent();

    expect(array_column(sseEvents($body)[0]['data']['rows'], 'message'))->toBe(['after the reconnect']);
});

it('sends a keepalive when nothing new arrived', function (): void {
    Http::fake(['loki.test:3100/*' => Http::response(lokiStreams([]))]);

    $body = $this->get(apiUrl('stream/logs', ['once' => 1]))->assertOk()->streamedContent();

    expect($body)->toContain(': keepalive')->not->toContain('event: rows');
});

it('streams new requests as an event: rows batch', function (): void {
    $now = time();

    Http::fake(['tempo.test:3200/api/search*' => Http::response(['traces' => [
        tempoHit('aaaa0000aaaa0000aaaa0000aaaa0001', 'GET /orders', $now - 5, 42.0, ['http.request.method' => 'GET', 'http.route' => '/orders', 'http.response.status_code' => 200]),
        tempoHit('aaaa0000aaaa0000aaaa0000aaaa0002', 'GET /old', $now - 120, 10.0, ['http.route' => '/old']),
    ]])]);

    $body = $this->get(apiUrl('stream/requests', ['once' => 1, 'since' => (string) (($now - 60) * 1_000_000_000)]))
        ->assertOk()
        ->assertHeader('Content-Type', 'text/event-stream; charset=UTF-8')
        ->streamedContent();

    $events = sseEvents($body);

    expect($events)->toHaveCount(1)
        ->and($events[0]['event'])->toBe('rows')
        ->and($events[0]['id'])->toBe((string) (($now - 5) * 1_000_000_000))
        ->and($events[0]['data']['rows'])->toHaveCount(1) // the older span is before the cursor
        ->and($events[0]['data']['rows'][0])->toMatchArray(['traceId' => 'aaaa0000aaaa0000aaaa0000aaaa0001', 'route' => '/orders', 'method' => 'GET']);

    expect(sentTraceql()[0])->toContain('kind = server');
});

it('reports a backend failure as an event: error and ends the stream', function (): void {
    Http::fake(['loki.test:3100/*' => Http::response('down', 503)]);

    $body = $this->get(apiUrl('stream/logs', ['once' => 1]))->assertOk()->streamedContent();
    $events = sseEvents($body);

    expect($events)->toHaveCount(1)
        ->and($events[0]['event'])->toBe('error')
        ->and($events[0]['data']['type'])->toBe('backend');
});

it('only streams logs and requests', function (): void {
    $this->get(apiUrl('stream/traces', ['once' => 1]))->assertNotFound();
});
