<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;

/**
 * A Prometheus instant vector. Declared here rather than reused from
 * another feature file, so this one runs on its own.
 *
 * @param  list<float>  $values
 * @return array<string, mixed>
 */
function signalVector(array $values): array
{
    return ['status' => 'success', 'data' => ['resultType' => 'vector', 'result' => array_map(
        static fn (float $v): array => ['metric' => [], 'value' => [1735689600, (string) $v]],
        $values,
    )]];
}

/**
 * A context signal whose metric does not exist is skipped silently — right
 * for an optional exporter, invisible for a typo. `--signals` is what makes
 * the silence visible, and this is the test that would have caught the
 * shipped defaults querying `_ratio` names nothing emitted.
 */
it('reports a signal that resolves and one that quietly matches nothing', function (): void {
    config()->set('telemetry-ui.context.signals', [
        ['label' => 'Host CPU', 'group' => 'host', 'unit' => 'ratio', 'query' => 'avg(system_cpu_utilization{{scope}})'],
        ['label' => 'Ghost', 'group' => 'host', 'unit' => 'ratio', 'query' => 'avg(nothing_emits_this{{scope}})'],
    ]);

    Http::fake([
        'prometheus.test:9090/api/v1/query?*nothing_emits_this*' => Http::response(signalVector([])),
        'prometheus.test:9090/api/v1/query*' => Http::response(signalVector([0.42])),
        'prometheus.test:9090/*' => Http::response(['status' => 'success', 'data' => []]),
        'tempo.test:3200/*' => Http::response(['traces' => []]),
        'loki.test:3100/*' => Http::response(['status' => 'success', 'data' => ['resultType' => 'streams', 'result' => []]]),
    ]);

    $this->artisan('telemetry-ui:check --signals')
        ->expectsOutputToContain('Context signals')
        ->expectsOutputToContain('Host CPU')
        ->expectsOutputToContain('Ghost')
        ->assertSuccessful();
});

it('says so when nothing is configured to correlate with', function (): void {
    config()->set('telemetry-ui.context.signals', []);

    Http::fake([
        'prometheus.test:9090/*' => Http::response(signalVector([])),
        'tempo.test:3200/*' => Http::response(['traces' => []]),
        'loki.test:3100/*' => Http::response(['status' => 'success', 'data' => ['resultType' => 'streams', 'result' => []]]),
    ]);

    $this->artisan('telemetry-ui:check --signals')
        ->expectsOutputToContain('No context signals configured')
        ->assertSuccessful();
});

it('leaves the shipped defaults querying names the emitter really produces', function (): void {
    /** @var list<array{label: string, query: string}> $signals */
    $signals = config('telemetry-ui.context.signals');
    $queries = implode(' ', array_column($signals, 'query'));

    // cboxdk/laravel-telemetry emits these; it does not append the
    // OpenTelemetry `_ratio` suffix, so a signal that only spells the
    // suffixed name resolves to nothing.
    expect($queries)->toContain('system_cpu_utilization{')
        ->toContain('system_memory_utilization{')
        ->toContain('system_cpu_load_average{')
        ->toContain('process_memory_rss_bytes')
        ->not->toContain('process_resident_memory_bytes');
});
