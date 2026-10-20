<?php

declare(strict_types=1);

use Cbox\TelemetryUi\Analysis\SignalContext;
use Cbox\TelemetryUi\Discovery\Catalogue;
use Cbox\TelemetryUi\Discovery\Discoverer;
use Cbox\TelemetryUi\Discovery\Exporter;
use Cbox\TelemetryUi\Discovery\InfrastructureMap;
use Cbox\TelemetryUi\Discovery\Signal;
use Cbox\TelemetryUi\Queries\Results\Span;
use Cbox\TelemetryUi\Queries\Results\SpanKind;
use Cbox\TelemetryUi\Queries\Results\Trace;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Discovery asks the metrics store what infrastructure exists instead of
 * being told. The behaviours worth pinning are the ones that keep a wrong
 * answer visible: only match on evidence, and record what did not match.
 */

/**
 * A Prometheus/Tempo pair that reports the given metric names, label values
 * and trace tag values.
 *
 * @param  list<string>  $metricNames
 * @param  list<string>  $instances
 * @param  list<string>  $hosts
 */
function fakeInfra(array $metricNames, array $instances, array $hosts, bool $signalsResolve = true): void
{
    Http::fake([
        'prometheus.test:9090/api/v1/label/__name__/values*' => Http::response(['status' => 'success', 'data' => $metricNames]),
        'prometheus.test:9090/api/v1/label/*/values*' => Http::response(['status' => 'success', 'data' => $instances]),
        'prometheus.test:9090/api/v1/query*' => Http::response(['status' => 'success', 'data' => ['resultType' => 'vector', 'result' => $signalsResolve
            ? [['metric' => [], 'value' => [1735689600, '1']]]
            : []]]),
        'tempo.test:3200/api/v2/search/tag/*' => Http::response(['tagValues' => array_map(static fn (string $h): array => ['type' => 'string', 'value' => $h], $hosts)]),
        'tempo.test:3200/*' => Http::response(['tagValues' => []]),
    ]);
}

beforeEach(fn () => Cache::flush());

it('ties an exporter instance to the host its traces name', function (): void {
    fakeInfra(
        metricNames: ['node_load1', 'node_cpu_seconds_total', 'node_memory_MemAvailable_bytes'],
        instances: ['web-3:9100'],
        hosts: ['web-3'],
    );

    $map = app(Discoverer::class)->discover();

    expect($map->exportersPresent)->toContain('node')
        ->and($map->targets)->toHaveCount(1)
        ->and($map->targets[0]->name)->toBe('web-3')
        ->and($map->targets[0]->exporter)->toBe('node')
        ->and($map->targets[0]->selector)->toBe('instance="web-3:9100"')
        ->and($map->unmatchedHosts)->toBe([]);
});

it('records an exporter nothing claims rather than guessing it into place', function (): void {
    fakeInfra(
        metricNames: ['node_load1'],
        instances: ['10.44.0.9:9100'],
        hosts: ['web-3'],
    );

    $map = app(Discoverer::class)->discover();

    expect($map->targets)->toBe([])
        // Both halves of the gap are reported: an exporter with no owner,
        // and a host with nothing watching it.
        ->and($map->unmatchedInstances)->toBe([['exporter' => 'node', 'instance' => '10.44.0.9:9100']])
        ->and($map->unmatchedHosts)->toBe(['web-3']);
});

it('finds the cache behind a dependency address, port and scheme aside', function (): void {
    fakeInfra(
        metricNames: ['redis_up', 'redis_memory_used_bytes'],
        instances: ['redis://cache-1:6379'],
        hosts: ['cache-1:6379'],
    );

    $map = app(Discoverer::class)->discover();

    expect($map->targets)->toHaveCount(1)
        ->and($map->targets[0]->exporter)->toBe('redis')
        ->and($map->for('cache-1:6379'))->toHaveCount(1);
});

it('records which signals returned nothing, so a wrong name is not silent', function (): void {
    fakeInfra(
        metricNames: ['node_load1'],
        instances: ['web-3:9100'],
        hosts: ['web-3'],
        signalsResolve: false,
    );

    $map = app(Discoverer::class)->discover();

    expect($map->targets[0]->signals)->toBe([])
        ->and($map->targets[0]->absent)->toBe(Catalogue::find('node')?->signalKeys());
});

it('caches the map and serves it again without probing', function (): void {
    fakeInfra(['node_load1'], ['web-3:9100'], ['web-3']);

    app(Discoverer::class)->discover();
    $before = count(Http::recorded());

    $map = app(Discoverer::class)->map();

    expect($map->targets)->toHaveCount(1)
        ->and(count(Http::recorded()))->toBe($before);
});

it('reports nothing at all when no known exporter is scraped', function (): void {
    fakeInfra(metricNames: ['http_server_request_duration_seconds_count'], instances: [], hosts: ['web-3']);

    $map = app(Discoverer::class)->discover();

    expect($map->exportersPresent)->toBe([])
        ->and($map->isEmpty())->toBeTrue();
});

it('strips scheme, path and port so both sides of a match normalise the same', function (): void {
    expect(InfrastructureMap::normalise('redis://Cache-1:6379'))->toBe('cache-1')
        ->and(InfrastructureMap::normalise('web-3:9100'))->toBe('web-3')
        ->and(InfrastructureMap::normalise('http://db-1/metrics'))->toBe('db-1');
});

it('fills the selector into every signal expression', function (): void {
    $signal = new Signal('memory', 'Memory', 'max(redis_memory_used_bytes{{selector}})', 'bytes');

    expect($signal->promql('addr="redis://cache-1:6379"'))
        ->toBe('max(redis_memory_used_bytes{addr="redis://cache-1:6379"})');
});

it('ships a catalogue whose signals all carry a selector token', function (): void {
    foreach (Catalogue::all() as $exporter) {
        expect($exporter->signals)->not->toBeEmpty()
            ->and($exporter->identityLabels)->not->toBeEmpty();

        foreach ($exporter->signals as $signal) {
            // Without the token a signal would query the whole fleet and
            // report another machine's memory as this one's. (toContain
            // treats a second argument as another needle, not a message.)
            expect($signal->expression)
                ->toContain('{selector}')
                ->and($signal->key)->not->toBe('');
        }
    }
});

it('names the exporters we verified against their own output', function (): void {
    $keys = array_map(static fn (Exporter $e): string => $e->key, Catalogue::all());

    expect($keys)->toEqualCanonicalizing([
        'node', 'redis', 'postgres', 'mysql', 'haproxy',
        'nginx', 'phpfpm', 'elasticsearch', 'mongodb', 'health',
    ]);
});

it('asks for names over an explicit window, because some backends answer nothing without one', function (): void {
    fakeInfra(['node_load1'], ['web-3:9100'], ['web-3']);

    app(Discoverer::class)->discover();

    $ranged = 0;

    Http::assertSent(function ($request) use (&$ranged): bool {
        $url = (string) $request->url();

        if (str_contains($url, '/search/tag/') && str_contains($url, 'start=') && str_contains($url, 'end=')) {
            $ranged++;
        }

        return true;
    });

    // Both tag lookups carry a range. A null range reads as "no names" on
    // some backends, and discovery would then match nothing, silently.
    expect($ranged)->toBe(2);
});

it('falls back to the metrics store for host names when traces cannot enumerate them', function (): void {
    Http::fake([
        'prometheus.test:9090/api/v1/label/__name__/values*' => Http::response(['status' => 'success', 'data' => ['node_load1']]),
        // The host label answers even though the traces backend did not.
        'prometheus.test:9090/api/v1/label/host_name/values*' => Http::response(['status' => 'success', 'data' => ['web-3']]),
        'prometheus.test:9090/api/v1/label/*/values*' => Http::response(['status' => 'success', 'data' => ['web-3:9100']]),
        'prometheus.test:9090/api/v1/query*' => Http::response(['status' => 'success', 'data' => ['resultType' => 'vector', 'result' => [['metric' => [], 'value' => [1735689600, '1']]]]]),
        'tempo.test:3200/*' => Http::response(['tagValues' => []]),
    ]);

    $map = app(Discoverer::class)->discover();

    expect($map->targets)->toHaveCount(1)
        ->and($map->targets[0]->name)->toBe('web-3');
});

it('scopes the instance lookup to each exporter, so they do not claim each other', function (): void {
    // `instance` is shared by every scrape job in a Prometheus. Unscoped,
    // the redis exporter is handed the node exporter's host, claims it,
    // and reports none of its signals — which looks like a broken cache.
    Http::fake([
        'prometheus.test:9090/api/v1/label/__name__/values*' => Http::response(['status' => 'success', 'data' => ['node_load1', 'redis_up']]),
        'prometheus.test:9090/api/v1/label/*/values*' => function ($request) {
            $match = (string) ($request->data()['match[]'] ?? '');

            return Http::response(['status' => 'success', 'data' => str_contains($match, 'redis')
                ? ['redis://cache-1:6379']
                : ['web-3:9100']]);
        },
        'prometheus.test:9090/api/v1/query*' => Http::response(['status' => 'success', 'data' => ['resultType' => 'vector', 'result' => [['metric' => [], 'value' => [1735689600, '1']]]]]),
        'tempo.test:3200/api/v2/search/tag/*' => Http::response(['tagValues' => [
            ['type' => 'string', 'value' => 'web-3'],
            ['type' => 'string', 'value' => 'cache-1:6379'],
        ]]),
        'tempo.test:3200/*' => Http::response(['tagValues' => []]),
    ]);

    $map = app(Discoverer::class)->discover();
    $byExporter = [];

    foreach ($map->targets as $target) {
        $byExporter[$target->exporter] = $target->name;
    }

    expect($byExporter)->toBe(['node' => 'web-3', 'redis' => 'cache-1:6379'])
        ->and($map->unmatchedInstances)->toBe([]);

    // Every label lookup carried a selector naming its own exporter.
    Http::assertSent(function ($request): bool {
        $url = (string) $request->url();

        return ! str_contains($url, '/label/instance/values')
            && ! str_contains($url, '/label/addr/values')
            || str_contains($url, 'match');
    });
});

/** Instant queries Prometheus was asked (the per-signal probes), and Tempo tag lookups. */
function probesAndTagLookups(): array
{
    $recorded = collect(Http::recorded())->map(fn (array $pair): string => rawurldecode($pair[0]->url()));

    return [
        $recorded->filter(fn (string $url): bool => str_contains($url, '/api/v1/query?'))->count(),
        $recorded->filter(fn (string $url): bool => str_contains($url, '/api/v2/search/tag/'))->count(),
    ];
}

it('opens a trace on a cold cache without discovering the whole fleet', function (): void {
    $fleet = array_map(static fn (int $i): string => "web-{$i}:9100", range(1, 60));
    fakeInfra(metricNames: ['node_load1', 'node_cpu_seconds_total'], instances: $fleet, hosts: array_map(static fn (string $i): string => explode(':', $i)[0], $fleet));
    $signals = count(Catalogue::find('node')?->signals ?? []);

    $targets = app(Discoverer::class)->targetsFor(['web-7']);

    [$probes, $tagLookups] = probesAndTagLookups();

    expect(array_map(static fn ($t): string => $t->instance, $targets))->toBe(['web-7:9100'])
        // web-7's own signals, not sixty hosts' worth, and no fleet-wide name lookup.
        ->and($probes)->toBe($signals)
        ->and($tagLookups)->toBe(0);
});

it('remembers what it found for a name, and that a name has nothing', function (): void {
    fakeInfra(metricNames: ['node_load1'], instances: ['web-3:9100'], hosts: ['web-3']);

    app(Discoverer::class)->targetsFor(['web-3', 'api.stripe.com']);
    $first = probesAndTagLookups()[0];

    $again = app(Discoverer::class)->targetsFor(['web-3', 'api.stripe.com']);

    expect(probesAndTagLookups()[0])->toBe($first)
        ->and(array_map(static fn ($t): string => $t->name, $again))->toBe(['web-3']);
});

it('answers from the full map when one has been discovered', function (): void {
    fakeInfra(metricNames: ['node_load1'], instances: ['web-3:9100', 'web-4:9100'], hosts: ['web-3', 'web-4']);
    app(Discoverer::class)->discover();
    $after = probesAndTagLookups()[0];

    $targets = app(Discoverer::class)->targetsFor(['web-4']);

    expect(array_map(static fn ($t): string => $t->instance, $targets))->toBe(['web-4:9100'])
        ->and(probesAndTagLookups()[0])->toBe($after);
});

it('reads a trace\'s exporter context on a cold cache from that trace\'s host only', function (): void {
    $fleet = array_map(static fn (int $i): string => "web-{$i}:9100", range(1, 60));
    fakeInfra(metricNames: ['node_load1', 'node_cpu_seconds_total'], instances: $fleet, hosts: array_map(static fn (string $i): string => explode(':', $i)[0], $fleet));
    config()->set('telemetry-ui.context.signals', []);
    $signals = count(Catalogue::find('node')?->signals ?? []);

    $span = new Span('s1', null, 'GET /orders', 'shop', SpanKind::Server, 1735689600_000_000_000, 1735689601_000_000_000, [], false);
    app(SignalContext::class)->forTrace(new Trace('t1', [$span], ['shop' => ['host.name' => 'web-7']]));

    expect(probesAndTagLookups())->toBe([$signals, 0]);
});

it('lists an exporter\'s instances over the last hour, not the store\'s whole retention', function (): void {
    fakeInfra(metricNames: ['node_load1'], instances: ['web-3:9100'], hosts: ['web-3']);

    app(Discoverer::class)->targetsFor(['web-3']);

    $lookups = collect(Http::recorded())->map(fn (array $pair): array => requestQuery($pair[0]))
        ->filter(fn (array $query, int $i): bool => str_contains(rawurldecode(Http::recorded()[$i][0]->url()), '/label/instance/values'));

    expect($lookups)->not->toBeEmpty()
        ->and($lookups->every(fn (array $q): bool => isset($q['start'], $q['end']) && (int) $q['end'] - (int) $q['start'] === 3600))->toBeTrue();
});
