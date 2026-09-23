<?php

declare(strict_types=1);

use Cbox\TelemetryUi\Facades\TelemetryUi;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Http::fake([
        'prometheus.test:9090/api/v1/label/*' => Http::response(['status' => 'success', 'data' => []]),
        'prometheus.test:9090/*' => Http::response(['status' => 'success', 'data' => ['resultType' => 'vector', 'result' => []]]),
    ]);
});

it('offers the host connections in bootstrap, in order', function (): void {
    TelemetryUi::connection('prod', 'Production', '/desktop/connect/prod');
    TelemetryUi::connection('staging', 'Staging', '/desktop/connect/staging');

    $this->getJson(apiUrl('bootstrap'))
        ->assertOk()
        ->assertJsonPath('connections', [
            ['value' => 'prod', 'label' => 'Production', 'url' => '/desktop/connect/prod'],
            ['value' => 'staging', 'label' => 'Staging', 'url' => '/desktop/connect/staging'],
        ]);
});

it('marks the connection the host says is current', function (): void {
    TelemetryUi::connection('prod', 'Production', '/desktop/connect/prod');
    TelemetryUi::connection('staging', 'Staging', '/desktop/connect/staging');
    TelemetryUi::currentConnection('staging');

    $this->getJson(apiUrl('bootstrap'))->assertOk()->assertJsonPath('currentConnection', 'staging');
});

it('claims no connection when the host has not said which is live', function (): void {
    TelemetryUi::connection('prod', 'Production', '/desktop/connect/prod');

    // '' — the client renders a placeholder rather than silently selecting the
    // first option and asserting a profile the host never confirmed.
    $this->getJson(apiUrl('bootstrap'))->assertOk()->assertJsonPath('currentConnection', '');
});

it('claims no connection when the host names one it never registered', function (): void {
    TelemetryUi::connection('prod', 'Production', '/desktop/connect/prod');
    TelemetryUi::currentConnection('gone');

    $this->getJson(apiUrl('bootstrap'))->assertOk()->assertJsonPath('currentConnection', '');

    expect(TelemetryUi::selectedConnection())->toBe('');
});

it('offers nothing at all when the host registers no connections', function (): void {
    $this->getJson(apiUrl('bootstrap'))
        ->assertOk()
        ->assertJsonPath('connections', [])
        ->assertJsonPath('currentConnection', '');

    expect(TelemetryUi::connections())->toBe([]);
});

it('hands host strings over as data, byte for byte', function (): void {
    TelemetryUi::connection('x', '<script>alert(1)</script>', '/a"onmouseover="alert(1)');

    // JSON is data: the value round-trips exactly and the client renders it as
    // text (React escapes). Nothing is pre-escaped or dropped server-side.
    $this->getJson(apiUrl('bootstrap'))
        ->assertOk()
        ->assertJsonPath('connections.0.label', '<script>alert(1)</script>')
        ->assertJsonPath('connections.0.url', '/a"onmouseover="alert(1)')
        ->assertHeader('Content-Type', 'application/json');
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
