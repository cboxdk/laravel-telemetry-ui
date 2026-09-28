<?php

declare(strict_types=1);

use Cbox\TelemetryUi\Connectors\ApiClient;
use Cbox\TelemetryUi\Connectors\ConnectionManager;
use Cbox\TelemetryUi\Connectors\Prometheus\MimirSource;
use Cbox\TelemetryUi\Connectors\Prometheus\PrometheusSource;
use Cbox\TelemetryUi\Connectors\Prometheus\WindowSplitter;
use Cbox\TelemetryUi\Queries\Compilers\PromqlCompiler;
use Cbox\TelemetryUi\Queries\Ir\MetricQuery;
use Cbox\TelemetryUi\Queries\Results\DataPoint;
use Cbox\TelemetryUi\Queries\Results\TimeSeries;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/*
 * Window-wide reads against Mimir run as range queries of slices: Mimir's
 * frontend splits a range query by day, while an instant query fetches the
 * whole window's chunks and is what a 24h p95 across every route hits
 * max_fetched_chunks_per_query with (reproduced on Mimir 3.2.1). Plain
 * Prometheus does better with the instant query (reproduced on 3.15), so
 * there it stays off.
 */

it('reads PromQL durations', function (string $window, ?int $seconds): void {
    expect(WindowSplitter::seconds($window))->toBe($seconds);
})->with([
    ['86400s', 86_400], ['15m', 900], ['1h30m', 5_400], ['7d', 604_800], ['1w', 604_800],
    ['', null], ['5m:1m', null], ['$__range', null],
]);

it('interpolates a quantile the way histogram_quantile does', function (float $q, float $expected): void {
    $buckets = ['0.1' => 10.0, '0.5' => 50.0, '1' => 90.0, '+Inf' => 100.0];

    expect(WindowSplitter::histogramQuantile($q, $buckets))->toEqualWithDelta($expected, 1e-9);
})->with([
    'in the first bucket' => [0.05, 0.05],
    'in a middle bucket' => [0.5, 0.5],
    'at a bucket edge' => [0.9, 1.0],
    // Past the last finite bound: Prometheus answers that bound.
    'in +Inf' => [0.95, 1.0],
]);

it('has no quantile without a +Inf bucket or without observations', function (): void {
    expect(WindowSplitter::histogramQuantile(0.95, ['0.1' => 1.0, '1' => 2.0]))->toBeNan()
        ->and(WindowSplitter::histogramQuantile(0.95, ['0.1' => 0.0, '+Inf' => 0.0]))->toBeNan();
});

it('is off unless a threshold is given, and leaves what does not add up alone', function (): void {
    $metric = new MetricQuery('http_server_request_duration_seconds_count');
    $on = new WindowSplitter(21_600);

    expect((new WindowSplitter)->plan($metric->increase('86400s')->sumBy()))->toBeNull()
        ->and($on->plan($metric->increase('3600s')->sumBy()))->toBeNull()
        ->and($on->plan($metric->increase('86400s')->avgBy('http_route')))->toBeNull()
        ->and($on->plan(MetricQuery::raw('sum(increase(x[1d]))')))->toBeNull();
});

it('slices a day into hours, and a quantile into bucket increases', function (): void {
    $plan = (new WindowSplitter(21_600))->plan((new MetricQuery('http_server_request_duration_seconds_bucket'))->quantile(0.95, '86400s', 'http_route'));

    expect([$plan['slices'], $plan['slice']])->toBe([24, 3_600])
        ->and((new PromqlCompiler)->compile($plan['inner']))
        ->toBe('sum by (http_route, le) (increase(http_server_request_duration_seconds_bucket[3600s]))');
});

it('grows long windows\' slices, but never past a day', function (string $window, int $slices, int $slice): void {
    $plan = (new WindowSplitter(21_600))->plan((new MetricQuery('x_bucket'))->quantile(0.95, $window));

    expect([$plan['slices'], $plan['slice']])->toBe([$slices, $slice]);
})->with([
    '7 days: hourly' => ['604800s', 168, 3_600],
    '30 days' => ['2592000s', 168, 15_428],
    '90 days' => ['7776000s', 168, 46_285],
    // A day's slice stays inside one of Mimir's daily parts.
    'a year: daily' => ['31536000s', 365, 86_400],
]);

it('adds the slices up: increases sum, a rate is the sum over the window', function (): void {
    $splitter = new WindowSplitter(21_600);
    $count = new MetricQuery('requests_total');
    $slices = [new TimeSeries(['route' => '/a'], [new DataPoint(1, 100.0), new DataPoint(2, 50.0), new DataPoint(3, NAN)])];

    $increase = $count->increase('86400s')->sumBy('route');
    $rate = $count->rate('86400s')->sumBy('route')->times(60);

    expect($splitter->combine($increase, $splitter->plan($increase), $slices, 3.0)[0]->value)->toBe(150.0)
        ->and($splitter->combine($rate, $splitter->plan($rate), $slices, 3.0)[0]->value)->toEqualWithDelta(150.0 / 86_400 * 60, 1e-12);
});

/**
 * @return ArrayObject<int, array{0: string, 1: string}> [endpoint, promql] of every request
 */
function fakeSlicedBackend(): ArrayObject
{
    $asked = new ArrayObject;

    Http::fake(function (Request $request) use ($asked) {
        $query = requestQuery($request);
        $asked[] = [str_contains($request->url(), 'query_range') ? 'range' : 'instant', (string) ($query['query'] ?? '')];

        if (str_contains($request->url(), 'query_range')) {
            // Two slices of bucket increases: 10 fast, 90 by 1s, 100 in all.
            $slice = fn (string $le, string $v): array => ['metric' => ['le' => $le], 'values' => [[1735686000, $v], [1735689600, $v]]];

            return Http::response(['status' => 'success', 'data' => ['resultType' => 'matrix', 'result' => [
                $slice('0.1', '5'), $slice('0.5', '25'), $slice('1', '45'), $slice('+Inf', '50'),
            ]]]);
        }

        return Http::response(['status' => 'success', 'data' => ['resultType' => 'vector', 'result' => [
            ['metric' => [], 'value' => [1735689600, '0.25']],
        ]]]);
    });

    return $asked;
}

it('asks Mimir for a day-wide p95 as a range query of slices', function (): void {
    $asked = fakeSlicedBackend();
    $p95 = (new MetricQuery('http_server_request_duration_seconds_bucket'))->quantile(0.95, '86400s');

    $result = (new MimirSource(new ApiClient('http://mimir.test')))->query($p95);

    expect($result[0]->value)->toBe(1.0)
        ->and($asked->getArrayCopy())->toBe([['range', 'sum by (le) (increase(http_server_request_duration_seconds_bucket[3600s]))']]);
});

it('asks Prometheus for it as the instant query it is', function (): void {
    $asked = fakeSlicedBackend();
    $p95 = (new MetricQuery('http_server_request_duration_seconds_bucket'))->quantile(0.95, '86400s');

    (new PrometheusSource(new ApiClient('http://prometheus.test')))->query($p95);

    expect($asked->getArrayCopy())->toBe([['instant', 'histogram_quantile(0.95, sum by (le) (rate(http_server_request_duration_seconds_bucket[86400s])))']]);
});

it('takes the connection config: slicing on for a prometheus-driver url that is Mimir, off for mimir', function (): void {
    config()->set('telemetry-ui.connections.metrics', ['driver' => 'prometheus', 'url' => 'http://mimir.test/prometheus', 'split_after' => 21600]);
    config()->set('telemetry-ui.connections.metrics-plain', ['driver' => 'mimir', 'url' => 'http://mimir.test', 'split_after' => 0]);
    $manager = app(ConnectionManager::class);
    $p95 = (new MetricQuery('x_bucket'))->quantile(0.95, '86400s');

    $asked = fakeSlicedBackend();
    $manager->metrics()->query($p95);
    $manager->metrics('metrics-plain')->query($p95);

    expect(array_column($asked->getArrayCopy(), 0))->toBe(['range', 'instant']);
});
