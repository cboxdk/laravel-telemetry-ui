<?php

declare(strict_types=1);

use Cbox\TelemetryUi\Panels\Panel;
use Cbox\TelemetryUi\TelemetryUiManager;
use Illuminate\Support\Facades\Http;

/** A whole custom chart panel in three lines, built on the panel kit. */
class DemoPromPanel extends Panel
{
    public function data(): array
    {
        return $this->promChart('Queue depth', $this->metric('queue_size'), unit: 'number', stat: 'Now');
    }
}

beforeEach(function (): void {
    // A panel is only addressable once it's registered on a page.
    app(TelemetryUiManager::class)->panel(DemoPromPanel::class);
});

it('renders a full chart panel from a single promChart() call', function (): void {
    Http::fake([
        'prometheus.test:9090/api/v1/query_range*' => Http::response([
            'status' => 'success',
            'data' => ['resultType' => 'matrix', 'result' => [
                ['metric' => [], 'values' => [[1735689600, '5'], [1735689660, '7']]],
            ]],
        ]),
        'prometheus.test:9090/api/v1/query*' => Http::response([
            'status' => 'success',
            'data' => ['resultType' => 'vector', 'result' => [['metric' => [], 'value' => [1735689600, '7']]]],
        ]),
    ]);

    $this->getJson(panelUrl('demo-prom-panel'))
        ->assertOk()
        ->assertJsonPath('kind', 'chart')
        ->assertJsonPath('title', 'Queue depth')    // chart title
        ->assertJsonPath('stats.0.label', 'Now')    // the headline stat
        ->assertJsonPath('stats.0.value', '7')      // its value (instant total)
        ->assertJsonPath('series.0.data.1.1', 7);

    // The scope was applied to the query the helper issued.
    Http::assertSent(fn ($request): bool => str_contains(rawurldecode($request->url()), 'queue_size'));
});

it('renders a clean error state when the backend fails', function (): void {
    Http::fake(['prometheus.test:9090/*' => Http::response('boom', 502)]);

    $this->getJson(panelUrl('demo-prom-panel'))
        ->assertOk()
        ->assertJsonPath('title', 'Queue depth')
        ->assertJsonPath('error', fn (mixed $error): bool => is_string($error) && str_contains($error, 'status 502')); // chartCard error path, no exception leaks out
});
