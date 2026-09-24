<?php

declare(strict_types=1);

use Cbox\TelemetryUi\TelemetryUiManager;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;

/**
 * The drawer's two halves: the trace itself (`?without=context`), which only
 * the trace store has to answer, and `traces/{id}/context`, which reads the
 * metrics and logs backends around it.
 */
function fakeTraceWithNeighbours(string $environment = 'production'): void
{
    Http::fake([
        'tempo.test:3200/api/traces/*' => Http::response(['batches' => [[
            'resource' => ['attributes' => [
                ['key' => 'service.name', 'value' => ['stringValue' => 'shop']],
                ['key' => 'deployment.environment.name', 'value' => ['stringValue' => $environment]],
            ]],
            'scopeSpans' => [['spans' => [
                ['spanId' => 'a1', 'name' => 'GET /orders', 'kind' => 'SPAN_KIND_SERVER', 'startTimeUnixNano' => '1735689600000000000', 'endTimeUnixNano' => '1735689601000000000'],
            ]]],
        ]]]),
        'prometheus.test:9090/*' => Http::response(['status' => 'success', 'data' => ['resultType' => 'matrix', 'result' => []]]),
        'loki.test:3100/*' => Http::response(lokiStreams([])),
    ]);
}

/** @return list<string> */
function backendsAskedBesidesTempo(): array
{
    return collect(Http::recorded())
        ->map(fn (array $pair): string => (string) $pair[0]->url())
        ->reject(fn (string $url): bool => str_contains($url, 'tempo.test'))
        ->values()
        ->all();
}

beforeEach(fn () => Gate::define('viewTelemetryUi', fn (?object $user = null, ?string $page = null): bool => true));

it('serves the trace alone, asking only the trace store, when told to leave the context out', function (): void {
    fakeTraceWithNeighbours();

    $this->getJson(apiUrl('traces/abc123abc123abc123abc123abc123ab', ['without' => 'context']))
        ->assertOk()
        ->assertJsonPath('root.name', 'GET /orders')
        ->assertJsonStructure(['waterfall', 'chain', 'report', 'services'])
        ->assertJsonMissingPath('context')
        ->assertJsonMissingPath('logs')
        ->assertJsonMissingPath('exceptions');

    expect(backendsAskedBesidesTempo())->toBe([]);
});

it('serves what surrounded a trace on its own endpoint', function (): void {
    fakeTraceWithNeighbours();

    $this->getJson(apiUrl('traces/abc123abc123abc123abc123abc123ab/context'))
        ->assertOk()
        ->assertJsonStructure(['traceId', 'services', 'context', 'profile', 'logs', 'logsMatch', 'exceptions'])
        ->assertJsonMissingPath('waterfall');

    expect(backendsAskedBesidesTempo())->not->toBe([]);
});

it('still serves the whole trace in one answer by default', function (): void {
    fakeTraceWithNeighbours();

    $this->getJson(apiUrl('traces/abc123abc123abc123abc123abc123ab'))
        ->assertOk()
        ->assertJsonStructure(['waterfall', 'context', 'logs', 'exceptions']);
});

it('holds the context endpoint to the same lock as the trace', function (): void {
    app(TelemetryUiManager::class)->restrictScopeUsing(fn ($user): array => ['environments' => ['production']]);
    fakeTraceWithNeighbours('staging');

    $this->getJson(apiUrl('traces/abc123abc123abc123abc123abc123ab/context'))->assertNotFound();

    expect(backendsAskedBesidesTempo())->toBe([]);
});
