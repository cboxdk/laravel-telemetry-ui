<?php

declare(strict_types=1);

use Cbox\TelemetryUi\Connectors\ConnectionManager;
use Cbox\TelemetryUi\Contracts\MetricsSource;
use Cbox\TelemetryUi\Facades\TelemetryUi;
use Cbox\TelemetryUi\Queries\Ir\MetricQuery;
use Cbox\TelemetryUi\Queries\Results\Sample;
use Cbox\TelemetryUi\Support\SchemaDetector;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;

/**
 * Fake a Prometheus holding exactly $names. Detection reads them from
 * /api/v1/label/__name__/values; the stub answers that endpoint and leaves
 * everything else to the generic card fakes.
 *
 * @param  list<string>  $names
 */
function fakeMetricNames(array $names): void
{
    Http::fake([
        'prometheus.test:9090/api/v1/label/__name__/values*' => Http::response([
            'status' => 'success',
            'data' => $names,
        ]),
        'prometheus.test:9090/*' => Http::response([
            'status' => 'success',
            'data' => ['resultType' => 'matrix', 'result' => []],
        ]),
    ]);
}

/**
 * The `match[]` selector a label-values request carried (parse_str turns the
 * `[]` suffix into a list), or '' for any other request.
 */
function matchSelector(Request $request): string
{
    $match = requestQuery($request)['match'] ?? null;

    return is_array($match) && is_string($match[0] ?? null) ? $match[0] : '';
}

/**
 * A metrics driver that answers detection the old way — one count() query per
 * pattern — because it does not declare EnumeratesMetricNames. Records every
 * PromQL string it is handed, so a test can count round trips.
 *
 * @param  list<string>  $names
 */
function countingMetricsSource(array $names): MetricsSource
{
    return new class($names) implements MetricsSource
    {
        /** @var list<string> */
        public array $queries = [];

        /** @param  list<string>  $names */
        public function __construct(private readonly array $names) {}

        public function query(MetricQuery $query, ?DateTimeInterface $at = null): array
        {
            $promql = $query->raw ?? '';

            $this->queries[] = $promql;

            preg_match('/__name__=~"(.*?)"/', $promql, $matches);

            $found = array_filter(
                $this->names,
                static fn (string $name): bool => preg_match('/^(?:'.($matches[1] ?? '').')$/D', $name) === 1,
            );

            return $found === [] ? [] : [new Sample([], 1735689600.0, (float) count($found))];
        }

        public function queryRange(MetricQuery $query, DateTimeInterface $start, DateTimeInterface $end, ?int $step = null): array
        {
            return [];
        }

        public function labelValues(string $label, ?string $match = null, ?DateTimeInterface $start = null, ?DateTimeInterface $end = null): array
        {
            return [];
        }
    };
}

it('shows the statamic page when statamic metrics exist', function (): void {
    Gate::define('viewTelemetryUi', fn (?object $user = null): bool => true);
    fakeMetricNames(allMetricNames());

    // The Statamic group and its per-family subpages appear.
    $this->get('/telemetry-ui')->assertOk()->assertSee('Statamic')->assertSee('Static Cache')->assertSee('Stache');
    $this->get('/telemetry-ui/statamic-cache')->assertOk()->assertSee('Static cache');
    $this->get('/telemetry-ui/statamic-glide')->assertOk()->assertSee('Glide');

    // One selector carries every pattern the registry declares.
    Http::assertSent(fn ($request): bool => str_contains($request->url(), '/api/v1/label/__name__/values')
        && str_contains(matchSelector($request), '(?:statamic_static_cache.*)')
        && str_contains(matchSelector($request), '(?:queue_metrics_.*)'));
});

it('hides and 404s the statamic subpages when no statamic metrics exist', function (): void {
    Gate::define('viewTelemetryUi', fn (?object $user = null): bool => true);
    fakeMetricNames([]);

    // Assert on the Statamic subpage labels, not the bare word "Statamic" —
    // the always-on "Statamic cache purge" annotation marker legitimately
    // carries it in the header regardless of detection.
    $this->get('/telemetry-ui')->assertOk()->assertDontSee('Static Cache')->assertDontSee('Stache');
    $this->get('/telemetry-ui/statamic-cache')->assertNotFound();
    $this->get('/telemetry-ui/statamic-glide')->assertNotFound();
});

it('resolves every detect pattern in one backend call, and sees the same pages as one call per pattern', function (): void {
    $names = allMetricNames();

    fakeMetricNames($names);

    $patterns = array_values(array_unique(array_filter(
        array_column(TelemetryUi::pages(), 'detect'),
        is_string(...),
    )));

    // Sixteen patterns is what the built-in registry declares today; the point
    // of the test is that the call count does not follow it.
    expect($patterns)->toHaveCount(16);

    $batched = TelemetryUi::visiblePages(app(SchemaDetector::class));

    Http::assertSentCount(1);

    // The same question asked of a driver without the capability: one query per
    // pattern, and — the invariant that matters — the very same pages.
    $source = countingMetricsSource($names);
    app(ConnectionManager::class)->extend('counting', fn (array $config): MetricsSource => $source);
    config()->set('telemetry-ui.connections.metrics.driver', 'counting');
    cache()->flush();

    $perPattern = TelemetryUi::visiblePages(app(SchemaDetector::class));

    expect($source->queries)->toHaveCount(16)
        ->and(array_keys($batched))->toBe(array_keys($perPattern));
});

it('asks only about the patterns it has not already cached', function (): void {
    fakeMetricNames(allMetricNames());

    $detector = app(SchemaDetector::class);

    expect($detector->hasMetricsMatching('statamic_stache.*'))->toBeTrue();

    Http::assertSentCount(1);

    $detector->detect(['statamic_stache.*', 'reverb_.*']);

    // Two calls total: the warm pattern is not asked about again, and the
    // second selector carries only the one that was missing.
    Http::assertSentCount(2);

    $selectors = array_map(
        static fn (array $pair): string => matchSelector($pair[0]),
        Http::recorded()->all(),
    );

    expect($selectors[1])->toContain('(?:reverb_.*)')
        ->and($selectors[1])->not->toContain('statamic_stache');
});

it('scopes detection to the selected service', function (): void {
    Gate::define('viewTelemetryUi', fn (?object $user = null): bool => true);

    // The fake pretends only "has-statamic" emits statamic_* metrics: the
    // scoped name list comes back with them unless it is scoped to another
    // service. Non-statamic families stay absent (those groups hide, fine).
    Http::fake(function (Request $request) {
        if (! str_contains($request->url(), '/api/v1/label/__name__/values')) {
            return Http::response([
                'status' => 'success',
                'data' => ['resultType' => 'vector', 'result' => []],
            ]);
        }

        $selector = matchSelector($request);

        return Http::response([
            'status' => 'success',
            'data' => str_contains($selector, 'service_name="no-statamic"')
                ? []
                : ['statamic_static_cache_hits_total', 'statamic_stache_warm_seconds'],
        ]);
    });

    // A service that emits statamic_* keeps the group…
    $this->get('/telemetry-ui?service=has-statamic')->assertOk()->assertSee('Statamic');

    // …a service that doesn't drops it, even though the fleet has it elsewhere.
    // (Check the subpage labels, not "Statamic": the annotation marker carries it.)
    $this->get('/telemetry-ui?service=no-statamic')->assertOk()->assertDontSee('Static Cache')->assertDontSee('Stache');
    $this->get('/telemetry-ui/statamic-cache?service=no-statamic')->assertNotFound();

    // The batched selector carries the service matcher, so the scoped question
    // is still the scoped question.
    Http::assertSent(fn ($request): bool => str_contains($request->url(), '/api/v1/label/__name__/values')
        && str_contains(matchSelector($request), 'service_name="no-statamic"'));
});

it('caches detection results', function (): void {
    fakeMetricNames(['statamic_stache_warm_seconds']);

    $detector = app(SchemaDetector::class);

    expect($detector->hasMetricsMatching('statamic_.*'))->toBeTrue()
        ->and($detector->hasMetricsMatching('statamic_.*'))->toBeTrue();

    Http::assertSentCount(1);
});

it('costs nothing once every pattern is cached', function (): void {
    fakeMetricNames(allMetricNames());

    $detector = app(SchemaDetector::class);

    TelemetryUi::visiblePages($detector);

    Http::assertSentCount(1);

    $warm = TelemetryUi::visiblePages($detector);

    // Still one: a fully warm cache issues no round trip at all.
    Http::assertSentCount(1);
    expect($warm)->not->toBeEmpty();
});

it('fails open without caching when the metrics backend is down', function (): void {
    Http::fake(['prometheus.test:9090/*' => Http::response('down', 503)]);

    $detector = app(SchemaDetector::class);

    expect($detector->hasMetricsMatching('statamic_.*'))->toBeTrue()
        ->and(cache()->get('telemetry-ui:detect:metrics:statamic_.*'))->toBeNull();
});

it('fails open for every pattern when the one batched call fails', function (): void {
    Http::fake(['prometheus.test:9090/*' => Http::response('down', 503)]);

    $detected = app(SchemaDetector::class)->detect(['statamic_.*', 'horizon_.*', 'reverb_.*']);

    expect($detected)->toBe(['statamic_.*' => true, 'horizon_.*' => true, 'reverb_.*' => true])
        ->and(cache()->get('telemetry-ui:detect:metrics:horizon_.*'))->toBeNull();

    // And the pages stay visible, which is the whole point of failing open.
    expect(array_keys(TelemetryUi::visiblePages(app(SchemaDetector::class))))
        ->toBe(array_keys(TelemetryUi::pages()));
});

it('fails open on a pattern that is not a usable regex', function (): void {
    fakeMetricNames(allMetricNames());

    // A backend would reject `statamic_(` too, and rejection has always meant
    // "show the page". Answering false here would hide a page over a typo.
    $detected = app(SchemaDetector::class)->detect(['statamic_(', 'horizon_.*']);

    expect($detected)->toBe(['statamic_(' => true, 'horizon_.*' => true])
        ->and(cache()->get('telemetry-ui:detect:metrics:statamic_('))->toBeNull()
        ->and(cache()->get('telemetry-ui:detect:metrics:horizon_.*'))->toBeTrue();
});

it('applies detection to third-party pages', function (): void {
    Gate::define('viewTelemetryUi', fn (?object $user = null): bool => true);
    fakeMetricNames([]);

    TelemetryUi::page('autoscale', 'Autoscale', group: 'Activity', detectMetric: 'autoscale_.*');

    $this->get('/telemetry-ui/autoscale')->assertNotFound();
});
