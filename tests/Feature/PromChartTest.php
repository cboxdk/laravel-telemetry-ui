<?php

declare(strict_types=1);

use Cbox\TelemetryUi\Cards\Card;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

/** A whole custom chart card in three lines, built on the card kit. */
class DemoPromCard extends Card
{
    public function render(): View
    {
        return $this->promChart('Queue depth', $this->metric('queue_size'), unit: 'number', stat: 'Now');
    }
}

it('renders a full chart card from a single promChart() call', function (): void {
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

    Livewire::test(DemoPromCard::class)
        ->assertSee('Queue depth')   // chart title
        ->assertSee('Now')           // the headline stat
        ->assertSee('7');            // its value (instant total)

    // The scope was applied to the query the helper issued.
    Http::assertSent(fn ($request): bool => str_contains(rawurldecode($request->url()), 'queue_size'));
});

it('renders a clean error state when the backend fails', function (): void {
    Http::fake(['prometheus.test:9090/*' => Http::response('boom', 502)]);

    Livewire::test(DemoPromCard::class)
        ->assertSee('Queue depth')
        ->assertSee('status 502'); // chartCard error path, no exception leaks out
});
