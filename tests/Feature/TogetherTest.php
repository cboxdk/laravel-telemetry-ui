<?php

declare(strict_types=1);

use Cbox\TelemetryUi\Connectors\ApiClient;
use Cbox\TelemetryUi\Connectors\BackendStatus;
use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Connectors\Together;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/*
 * A panel's independent reads go to the backend at the same time: the panel
 * takes as long as its slowest read, not the sum of them all.
 */

it('sends a six-query panel\'s reads to the backend at the same time', function (int $concurrency, int $open): void {
    [$url, $peak] = slowPrometheus(200);
    config()->set('telemetry-ui.connections.metrics.url', $url);
    config()->set('telemetry-ui.cache.ttl', 0);
    config()->set('telemetry-ui.concurrency', $concurrency);
    Http::fake(['loki.test/*' => Http::response(['status' => 'success', 'data' => ['resultType' => 'streams', 'result' => []]])]);

    $panel = $this->getJson(panelUrl('request-duration', ['period' => '1h']))->assertOk()->json();

    expect($panel['error'] ?? null)->toBeNull()
        ->and($panel['series'])->not->toBeEmpty()
        ->and($peak())->toBe($open);
})->with([
    'all six at once' => [8, 6],
    'no more than the limit' => [4, 4],
    'one after the other, when turned off' => [1, 1],
]);

it('sends every read that is waiting in the same round, and the next request of each in the next', function (): void {
    $asked = [];

    Http::fake(function (Request $request) use (&$asked) {
        $asked[] = requestQuery($request)['query'] ?? '';

        return Http::response(['status' => 'success', 'data' => ['resultType' => 'vector', 'result' => []]]);
    });

    $client = new ApiClient('http://prometheus.test');
    $read = static fn (string $name): Closure => static function () use ($client, $name): string {
        $client->get('/api/v1/query', ['query' => $name.'1']);
        $client->get('/api/v1/query', ['query' => $name.'2']);

        return $name;
    };

    $results = Together::run(['a' => $read('a'), 'b' => $read('b'), 'c' => $read('c')]);

    expect($results)->toBe(['a' => 'a', 'b' => 'b', 'c' => 'c'])
        ->and($asked)->toBe(['a1', 'b1', 'c1', 'a2', 'b2', 'c2']);
});

it('keeps the answers of the reads that worked when one times out', function (): void {
    Http::fake(function (Request $request) {
        if ((requestQuery($request)['query'] ?? '') === 'slow') {
            throw new ConnectionException('cURL error 28: Operation timed out after 10001 milliseconds with 0 bytes received');
        }

        return Http::response(['status' => 'success', 'data' => ['resultType' => 'vector', 'result' => []]]);
    });

    $client = new ApiClient('http://prometheus.test', timeout: 10.0);

    $results = Together::run([
        'fast' => fn (): array => $client->get('/api/v1/query', ['query' => 'fast']),
        'slow' => fn (): array => $client->get('/api/v1/query', ['query' => 'slow']),
    ]);

    expect($results['fast'])->toBe(['status' => 'success', 'data' => ['resultType' => 'vector', 'result' => []]])
        ->and($results['slow'])->toBeInstanceOf(SourceException::class)
        ->and($results['slow']->status)->toBe(BackendStatus::Timeout)
        ->and($results['slow']->getMessage())->toContain('did not answer within 10s');
});

it('still retries a dropped connection on a pooled request', function (): void {
    $calls = 0;

    Http::fake(function () use (&$calls) {
        if (++$calls === 1) {
            throw new ConnectionException('cURL error 56: Connection reset by peer');
        }

        return Http::response(['status' => 'success', 'data' => ['resultType' => 'vector', 'result' => []]]);
    });

    $client = new ApiClient('http://prometheus.test', retries: 2);

    $results = Together::run([
        fn (): array => $client->get('/api/v1/query', ['query' => 'a']),
        fn (): string => 'no request',
    ]);

    expect($results[0])->toBeArray()->and($calls)->toBe(2);
});

it('says a timed-out read is missing and charts the rest', function (): void {
    Http::fake(function (Request $request) {
        if (str_contains((string) (requestQuery($request)['query'] ?? ''), '_bucket')) {
            throw new ConnectionException('cURL error 28: Operation timed out after 10001 milliseconds with 0 bytes received');
        }

        return str_contains($request->url(), 'query_range')
            ? Http::response(['status' => 'success', 'data' => ['resultType' => 'matrix', 'result' => [['metric' => [], 'values' => [[1735689600, '2']]]]]])
            : Http::response(['status' => 'success', 'data' => ['resultType' => 'vector', 'result' => [['metric' => [], 'value' => [1735689600, '10']]]]]);
    });

    $panel = $this->getJson(panelUrl('request-duration', ['period' => '1h']))->assertOk()->json();

    expect(collect($panel['series'])->pluck('name')->all())->toBe(['AVG'])
        ->and($panel['note'])->toContain('did not answer within');
});
