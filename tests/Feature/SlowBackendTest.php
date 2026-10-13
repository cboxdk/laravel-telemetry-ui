<?php

declare(strict_types=1);

use Cbox\TelemetryUi\Connectors\ApiClient;
use Cbox\TelemetryUi\Connectors\BackendStatus;
use Cbox\TelemetryUi\Connectors\Prometheus\PrometheusSource;
use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Queries\Ir\MetricQuery;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/*
 * A busy backend: the window-wide p95 across every route is the query that
 * runs out of time. The panel must say so, and keep what did answer.
 */

const TIMED_OUT = 'cURL error 28: Operation timed out after 10001 milliseconds with 0 bytes received';

/**
 * Prometheus answers everything but the queries whose PromQL contains
 * `$slow`, which time out. Returns the list of PromQL it was asked.
 *
 * @return ArrayObject<int, string>
 */
function slowFor(string $slow): ArrayObject
{
    $asked = new ArrayObject;

    Http::fake(function (Request $request) use ($slow, $asked) {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
        $promql = (string) ($query['query'] ?? $request->data()['query'] ?? '');
        $asked[] = $promql;

        if (str_contains($promql, $slow)) {
            throw new ConnectionException(TIMED_OUT);
        }

        if (str_contains($request->url(), 'query_range')) {
            return Http::response(['status' => 'success', 'data' => ['resultType' => 'matrix', 'result' => [
                ['metric' => ['http_route' => '/orders', 'http_request_method' => 'GET'], 'values' => [[1735689600, '4'], [1735689660, '6']]],
            ]]]);
        }

        return Http::response(['status' => 'success', 'data' => ['resultType' => 'vector', 'result' => [
            ['metric' => ['http_route' => '/orders', 'http_request_method' => 'GET', 'http_response_status_code' => '200'], 'value' => [1735689600, '1200']],
        ]]]);
    });

    return $asked;
}

it('calls a timeout a timeout, with what to do about it', function (): void {
    Http::fake(fn () => throw new ConnectionException(TIMED_OUT));

    try {
        (new ApiClient('http://prometheus.test', timeout: 10.0))->get('/api/v1/query');
        $this->fail('expected a SourceException');
    } catch (SourceException $exception) {
        expect($exception->status)->toBe(BackendStatus::Timeout)
            ->and($exception->getMessage())->toContain('did not answer within 10s')
            ->and($exception->getMessage())->toContain('shorter window')
            ->and($exception->getMessage())->not->toContain('prometheus.test');
    }
});

it('calls an answer cut off mid-stream dropped, not a certificate problem', function (string $reason): void {
    Http::fake(fn () => throw new ConnectionException($reason));

    try {
        (new ApiClient('https://prometheus.test'))->get('/api/v1/query_range');
        $this->fail('expected a SourceException');
    } catch (SourceException $exception) {
        expect($exception->status)->toBe(BackendStatus::Dropped)
            ->and($exception->getMessage())->toContain('cut its answer off')
            ->and($exception->getMessage())->not->toContain('certificate');
    }
})->with([
    // What nginx in front of telemetryd produced for a 1.2 MB answer.
    'https, cut off' => ['cURL error 56: OpenSSL SSL_read: OpenSSL/3.6.4: error:0A000126:SSL routines::unexpected eof while reading, errno 0'],
    'http/2 stream reset' => ['cURL error 92: HTTP/2 stream 1 was not closed cleanly: INTERNAL_ERROR (err 2)'],
    'partial body' => ['cURL error 18: transfer closed with 1046080 bytes remaining to read'],
]);

it('still calls a real certificate failure a certificate failure', function (): void {
    Http::fake(fn () => throw new ConnectionException('cURL error 60: SSL certificate problem: unable to get local issuer certificate'));

    expect(fn () => (new ApiClient('https://prometheus.test'))->get('/x'))
        ->toThrow(SourceException::class, 'TLS certificate could not be verified');
});

it('keeps an unreachable backend unreachable', function (): void {
    Http::fake(fn () => throw new ConnectionException('cURL error 7: Failed to connect to prometheus.test port 9090: Connection refused'));

    expect(fn () => (new ApiClient('http://prometheus.test'))->get('/api/v1/query'))
        ->toThrow(SourceException::class, 'Could not reach the telemetry backend.');
});

it('calls a connection that never came up unreachable, not a slow query', function (string $reason): void {
    Http::fake(fn () => throw new ConnectionException($reason));

    try {
        (new ApiClient('http://prometheus.test', timeout: 30.0))->get('/api/v1/query');
        $this->fail('expected a SourceException');
    } catch (SourceException $exception) {
        expect($exception->status)->toBe(BackendStatus::Unreachable)
            ->and($exception->getMessage())->toBe('Could not reach the telemetry backend.');
    }
})->with([
    'errno 7, connect timed out' => ['cURL error 7: Failed to connect to prometheus.test port 9090 after 75002 ms: Connection timed out'],
    'connect timeout' => ['cURL error 28: Connection timed out after 5001 milliseconds'],
    'name lookup' => ['cURL error 28: Resolving timed out after 5000 milliseconds'],
]);

it('says how long it actually waited, not a timeout it was never given', function (): void {
    Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out after 30002 milliseconds with 0 bytes received'));

    expect(fn () => (new ApiClient('http://prometheus.test'))->get('/api/v1/query'))
        ->toThrow(SourceException::class, 'did not answer within 30s');
});

it('draws the duration chart when only the day-wide p95 times out', function (): void {
    slowFor('_bucket[3600s]');

    $this->getJson(panelUrl('request-duration', ['period' => '1h']))
        ->assertOk()
        ->assertJsonPath('error', null)
        ->assertJsonPath('series.0.name', 'AVG')
        ->assertJsonPath('series.1.name', 'P95')
        ->assertJsonPath('stats.1.value', '—')
        ->assertJsonPath('note', fn (string $note): bool => str_contains($note, 'did not answer within'));
});

it('lists the routes with a dash for p95 when the per-route p95 times out', function (): void {
    slowFor('_bucket[3600s]');

    $this->getJson(panelUrl('routes-needing-attention', ['period' => '1h']))
        ->assertOk()
        ->assertJsonPath('error', null)
        ->assertJsonPath('rows.0.route.v', '/orders')
        ->assertJsonPath('rows.0.total.raw', 1200)
        ->assertJsonPath('rows.0.p95.v', '—')
        ->assertJsonPath('note', fn (string $note): bool => str_contains($note, 'did not answer within'));
});

it('stops asking once a read has timed out, instead of waiting out the timeout again', function (): void {
    // The first optional read times out; the two after it are never sent.
    $asked = slowFor('_sum[3600s]');

    $this->getJson(panelUrl('routes-needing-attention', ['period' => '1h']))->assertOk();

    expect(array_filter($asked->getArrayCopy(), static fn (string $q): bool => str_contains($q, '_bucket')))->toBe([]);
});

it('still fails the table when the counts themselves cannot be read', function (): void {
    slowFor('http_server_request_duration_seconds_count');

    $this->getJson(panelUrl('routes-needing-attention', ['period' => '1h']))
        ->assertOk()
        ->assertJsonPath('rows', [])
        ->assertJsonPath('error', fn (string $error): bool => str_contains($error, 'did not answer within'));
});

it('says why the backend refused a query, not only its status', function (): void {
    // Prometheus 3.15's own answer when a query is over --query.max-samples.
    Http::fake(['prometheus.test:9090/*' => Http::response([
        'status' => 'error',
        'errorType' => 'execution',
        'error' => 'query processing would load too many samples into memory in query execution',
    ], 422)]);

    expect(fn () => (new PrometheusSource(new ApiClient('http://prometheus.test:9090')))->query(new MetricQuery('up')))
        ->toThrow(SourceException::class, 'The telemetry backend refused the query (status 422): query processing would load too many samples into memory in query execution');
});

it('does not echo a body that is not a backend error', function (): void {
    Http::fake(['prometheus.test:9090/*' => Http::response('<html>Bad gateway at https://internal.example/</html>', 502)]);

    try {
        (new PrometheusSource(new ApiClient('http://prometheus.test:9090')))->query(new MetricQuery('up'));
        $this->fail('expected a SourceException');
    } catch (SourceException $exception) {
        expect($exception->getMessage())->toBe('The telemetry backend returned status 502.');
    }
});
