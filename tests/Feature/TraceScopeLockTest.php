<?php

declare(strict_types=1);

use Cbox\TelemetryUi\TelemetryUiManager;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;

/** One span, one service — enough to say whose trace it is. */
function fakeTraceOf(string $service): void
{
    Http::fake([
        'tempo.test:3200/api/traces/*' => Http::response(['batches' => [[
            'resource' => ['attributes' => [['key' => 'service.name', 'value' => ['stringValue' => $service]]]],
            'scopeSpans' => [['spans' => [
                ['spanId' => 'a1', 'name' => 'GET /orders', 'kind' => 'SPAN_KIND_SERVER', 'startTimeUnixNano' => '1735689600000000000', 'endTimeUnixNano' => '1735689601000000000'],
            ]]],
        ]]]),
        '*' => Http::response(lokiStreams([])),
    ]);
}

it('treats a trace from a service outside the lock as absent', function (): void {
    Gate::define('viewTelemetryUi', fn (?object $user = null, ?string $page = null): bool => true);
    app(TelemetryUiManager::class)->restrictScopeUsing(fn ($user): array => ['services' => ['shop']]);
    fakeTraceOf('billing');

    // A trace id is a deep link anyone can paste — the lock still applies.
    $this->getJson(apiUrl('traces/abc123abc123abc123abc123abc123ab'))->assertNotFound();
});

it('serves a trace from a service inside the lock', function (): void {
    Gate::define('viewTelemetryUi', fn (?object $user = null, ?string $page = null): bool => true);
    app(TelemetryUiManager::class)->restrictScopeUsing(fn ($user): array => ['services' => ['shop']]);
    fakeTraceOf('shop');

    $this->getJson(apiUrl('traces/abc123abc123abc123abc123abc123ab'))
        ->assertOk()
        ->assertJsonPath('root.name', 'GET /orders');
});

it('serves any trace when no lock is configured', function (): void {
    Gate::define('viewTelemetryUi', fn (?object $user = null, ?string $page = null): bool => true);
    fakeTraceOf('billing');

    $this->getJson(apiUrl('traces/abc123abc123abc123abc123abc123ab'))->assertOk();
});
