<?php

declare(strict_types=1);

use Cbox\TelemetryUi\Connectors\ConnectionManager;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/*
 * Tempo searches at most 168h at once (query_frontend.search.max_duration)
 * and answers a longer search, or tag-value lookup, with a 400. A 14- or
 * 30-day window searches its newest 168h instead, and says so.
 */

/**
 * Tempo as it answers: a 400 with its plain-text reason for a window over
 * 168h, one request otherwise. Returns the windows it was asked, in seconds.
 *
 * @return ArrayObject<int, int>
 */
function tempoWithSearchLimit(): ArrayObject
{
    $windows = new ArrayObject;
    $now = time() - 60;

    Http::fake(function (Request $request) use ($windows, $now) {
        if (! str_contains($request->url(), 'tempo.test')) {
            return Http::response(['status' => 'success', 'data' => ['resultType' => 'vector', 'result' => []]]);
        }

        $query = requestQuery($request);
        $window = (int) ($query['end'] ?? 0) - (int) ($query['start'] ?? 0);
        $windows[] = $window;

        if ($window > 168 * 3600) {
            return Http::response("range specified by start and end exceeds 168h0m0s. received start={$query['start']} end={$query['end']}", 400);
        }

        return str_contains($request->url(), '/search/tag/')
            ? Http::response(['tagValues' => [['type' => 'string', 'value' => 'checkout']]])
            : Http::response(['traces' => [tempoHit('t1', 'GET /orders', $now, 12.0, ['http.request.method' => 'GET', 'url.path' => '/orders', 'http.response.status_code' => 200])]]);
    });

    return $windows;
}

it('searches the newest 168h of a 30-day window instead of failing', function (): void {
    $windows = tempoWithSearchLimit();

    $panel = $this->getJson(panelUrl('request-log', ['period' => '30d']))->assertOk()->json();

    expect($panel['error'] ?? null)->toBeNull()
        ->and($panel['rows'])->toHaveCount(1)
        ->and($panel['note'])->toContain('Traces are from the newest 168h of this window only')
        ->and(max($windows->getArrayCopy()))->toBeLessThanOrEqual(168 * 3600);
});

it('says nothing about the search window when the window fits', function (): void {
    tempoWithSearchLimit();

    $panel = $this->getJson(panelUrl('request-log', ['period' => '24h']))->assertOk()->json();

    expect($panel['note'] ?? '')->not->toContain('newest 168h');
});

it('tells Explore where a cut search really started, and takes rates over that part', function (): void {
    tempoWithSearchLimit();

    $result = $this->getJson(apiUrl('explore/requests', ['period' => '30d']))->assertOk()->json();

    $searchedFrom = $result['sample']['searchedFrom'];

    expect($searchedFrom)->toBeInt()
        ->and(intdiv($result['range']['end'] - $searchedFrom, 1000))->toBe(168 * 3600)
        // One request over 168h, not over 30 days.
        ->and($result['stats']['perMinute'])->toEqualWithDelta(1 / (168 * 60), 1e-9);
});

it('leaves searchedFrom empty when the window fits', function (): void {
    tempoWithSearchLimit();

    $result = $this->getJson(apiUrl('explore/requests', ['period' => '24h']))->assertOk()->json();

    expect($result['sample']['searchedFrom'])->toBeNull();
});

it('cuts a tag-value lookup to the same limit', function (): void {
    $windows = tempoWithSearchLimit();

    $values = app(ConnectionManager::class)->traces()
        ->tagValues('resource.service.name', null, new DateTimeImmutable('-30 days'), new DateTimeImmutable);

    expect($values)->toBe(['checkout'])
        ->and($windows->getArrayCopy())->toBe([168 * 3600]);
});

it('searches the whole window when the limit is turned off', function (): void {
    config()->set('telemetry-ui.connections.traces.max_search', 0);
    $windows = tempoWithSearchLimit();

    $panel = $this->getJson(panelUrl('request-log', ['period' => '30d']))->assertOk()->json();

    expect($panel['error'])->toContain('status 400')
        ->and(max($windows->getArrayCopy()))->toBeGreaterThan(168 * 3600);
});
