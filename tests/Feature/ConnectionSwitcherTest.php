<?php

declare(strict_types=1);

use Cbox\TelemetryUi\Facades\TelemetryUi;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Gate::define('viewTelemetryUi', fn (?object $user = null, ?string $page = null): bool => true);

    Http::fake([
        'prometheus.test:9090/api/v1/label/*' => Http::response(['status' => 'success', 'data' => []]),
        'prometheus.test:9090/*' => Http::response(['status' => 'success', 'data' => ['resultType' => 'vector', 'result' => []]]),
    ]);
});

it('renders the host connections as a native select in the header', function (): void {
    TelemetryUi::connection('prod', 'Production', '/desktop/connect/prod');
    TelemetryUi::connection('staging', 'Staging', '/desktop/connect/staging');

    $this->get('/telemetry-ui')
        ->assertOk()
        ->assertSee('<select class="tui-connection-select"', false)
        ->assertSee('<option value="prod" data-url="/desktop/connect/prod">Production</option>', false)
        ->assertSee('<option value="staging" data-url="/desktop/connect/staging">Staging</option>', false);
});

it('marks the connection the host says is current', function (): void {
    TelemetryUi::connection('prod', 'Production', '/desktop/connect/prod');
    TelemetryUi::connection('staging', 'Staging', '/desktop/connect/staging');
    TelemetryUi::currentConnection('staging');

    $this->get('/telemetry-ui')
        ->assertOk()
        ->assertSee('<option value="staging" data-url="/desktop/connect/staging" selected>Staging</option>', false)
        ->assertDontSee('<option value="prod" data-url="/desktop/connect/prod" selected>', false)
        // With a real selection there is no placeholder to hold the slot.
        ->assertDontSee('Connection…');
});

it('claims no connection when the host has not said which is live', function (): void {
    TelemetryUi::connection('prod', 'Production', '/desktop/connect/prod');

    // Without a placeholder the browser would silently select the first option,
    // so the control would assert a profile the host never confirmed.
    $this->get('/telemetry-ui')
        ->assertOk()
        ->assertSee('<option value="" selected disabled>Connection…</option>', false);
});

it('claims no connection when the host names one it never registered', function (): void {
    TelemetryUi::connection('prod', 'Production', '/desktop/connect/prod');
    TelemetryUi::currentConnection('gone');

    $this->get('/telemetry-ui')
        ->assertOk()
        ->assertSee('<option value="" selected disabled>Connection…</option>', false);

    expect(TelemetryUi::selectedConnection())->toBe('');
});

it('renders nothing at all when the host registers no connections', function (): void {
    $this->get('/telemetry-ui')
        ->assertOk()
        ->assertDontSee('tui-connection-select')
        ->assertDontSee('Connection…');

    expect(TelemetryUi::connections())->toBe([]);
});

it('renders on trace pages too, so a drilled-in reader can still switch', function (): void {
    TelemetryUi::connection('prod', 'Production', '/desktop/connect/prod');

    Http::fake([
        'tempo.test:3200/*' => Http::response(['batches' => []]),
        'prometheus.test:9090/api/v1/label/*' => Http::response(['status' => 'success', 'data' => []]),
        'prometheus.test:9090/*' => Http::response(['status' => 'success', 'data' => ['resultType' => 'vector', 'result' => []]]),
    ]);

    $this->get('/telemetry-ui/traces/'.str_repeat('a', 32))
        ->assertOk()
        ->assertSee('data-url="/desktop/connect/prod"', false);
});

it('escapes the label and the url rather than trusting the host', function (): void {
    TelemetryUi::connection('x', '<script>alert(1)</script>', '/a"onmouseover="alert(1)');

    $this->get('/telemetry-ui')
        ->assertOk()
        ->assertDontSee('<script>alert(1)</script>', false)
        ->assertDontSee('onmouseover="alert(1)"', false)
        // Escaped, not dropped — the option is still usable.
        ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false);
});

it('replaces a connection registered twice under one value', function (): void {
    TelemetryUi::connection('prod', 'First', '/first');
    TelemetryUi::connection('prod', 'Second', '/second');

    expect(TelemetryUi::connections())->toHaveCount(1)
        ->and(TelemetryUi::connections()[0]->label)->toBe('Second')
        ->and(TelemetryUi::connections()[0]->url)->toBe('/second');
});

it('removes a connection', function (): void {
    TelemetryUi::connection('prod', 'Production', '/prod');
    TelemetryUi::removeConnection('prod');

    expect(TelemetryUi::connections())->toBe([]);
});

it('keeps the registration order the host chose', function (): void {
    TelemetryUi::connection('c', 'C', '/c');
    TelemetryUi::connection('a', 'A', '/a');
    TelemetryUi::connection('b', 'B', '/b');

    expect(array_map(fn ($connection): string => $connection->value, TelemetryUi::connections()))
        ->toBe(['c', 'a', 'b']);
});
