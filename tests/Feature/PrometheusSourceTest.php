<?php

declare(strict_types=1);

use Cbox\TelemetryUi\Connectors\ApiClient;
use Cbox\TelemetryUi\Connectors\Prometheus\PrometheusSource;
use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Queries\Results\Sample;
use Cbox\TelemetryUi\Queries\Results\TimeSeries;
use Illuminate\Support\Facades\Http;

function prometheus(): PrometheusSource
{
    return new PrometheusSource(new ApiClient('http://prometheus.test:9090'));
}

it('parses instant vector queries', function (): void {
    Http::fake([
        'prometheus.test:9090/api/v1/query*' => Http::response([
            'status' => 'success',
            'data' => [
                'resultType' => 'vector',
                'result' => [
                    [
                        'metric' => ['service_name' => 'checkout', 'http_route' => '/orders'],
                        'value' => [1735689600, '42.5'],
                    ],
                    [
                        'metric' => ['service_name' => 'billing'],
                        'value' => [1735689600, '7'],
                    ],
                ],
            ],
        ]),
    ]);

    $samples = prometheus()->query('sum by (service_name) (rate(http_server_request_duration_milliseconds_count[5m]))');

    expect($samples)->toHaveCount(2)
        ->and($samples[0])->toBeInstanceOf(Sample::class)
        ->and($samples[0]->labels)->toBe(['service_name' => 'checkout', 'http_route' => '/orders'])
        ->and($samples[0]->value)->toBe(42.5)
        ->and($samples[1]->value)->toBe(7.0);

    Http::assertSent(fn ($request): bool => str_contains($request->url(), '/api/v1/query')
        && requestQuery($request)['query'] === 'sum by (service_name) (rate(http_server_request_duration_milliseconds_count[5m]))');
});

it('parses range queries into time series with a derived step', function (): void {
    Http::fake([
        'prometheus.test:9090/api/v1/query_range*' => Http::response([
            'status' => 'success',
            'data' => [
                'resultType' => 'matrix',
                'result' => [
                    [
                        'metric' => ['service_name' => 'checkout'],
                        'values' => [[1735689600, '10'], [1735689660, '12.5']],
                    ],
                ],
            ],
        ]),
    ]);

    $start = new DateTimeImmutable('@1735686000');
    $end = new DateTimeImmutable('@1735689600');

    $series = prometheus()->queryRange('up', $start, $end);

    expect($series)->toHaveCount(1)
        ->and($series[0])->toBeInstanceOf(TimeSeries::class)
        ->and($series[0]->labels)->toBe(['service_name' => 'checkout'])
        ->and($series[0]->points)->toHaveCount(2)
        ->and($series[0]->points[1]->value)->toBe(12.5)
        ->and($series[0]->toChartData()[0])->toBe([1735689600000.0, 10.0]);

    // 3600s window / 250 target points => 15s minimum step.
    Http::assertSent(fn ($request): bool => requestQuery($request) === [
        'query' => 'up',
        'start' => '1735686000',
        'end' => '1735689600',
        'step' => '15',
    ]);
});

it('lists label values with a series matcher', function (): void {
    Http::fake([
        'prometheus.test:9090/api/v1/label/service_name/values*' => Http::response([
            'status' => 'success',
            'data' => ['billing', 'checkout'],
        ]),
    ]);

    $values = prometheus()->labelValues('service_name', 'http_server_request_duration_milliseconds_count');

    expect($values)->toBe(['billing', 'checkout']);

    Http::assertSent(fn ($request): bool => requestQuery($request)['match'] === ['http_server_request_duration_milliseconds_count']);
});

it('throws on error envelopes', function (): void {
    Http::fake([
        'prometheus.test:9090/*' => Http::response([
            'status' => 'error',
            'error' => 'parse error: unexpected identifier',
        ]),
    ]);

    prometheus()->query('sum by (');
})->throws(SourceException::class, 'parse error');

it('throws on http failures', function (): void {
    Http::fake([
        'prometheus.test:9090/*' => Http::response('upstream unavailable', 502),
    ]);

    prometheus()->query('up');
})->throws(SourceException::class, 'status 502');
