<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/*
 * The environment scope on a log query: in the stream selector where Loki
 * indexes the label, so it reads only that environment's streams, and a
 * pipeline filter where the label is structured metadata (otel-lgtm), where
 * a stream matcher would match nothing.
 */

/**
 * Loki whose label names API answers $labels (null: a 500), and an empty
 * stream result for every query.
 *
 * @param  list<string>|null  $labels
 */
function lokiIndexing(?array $labels): void
{
    Http::fake(function (Request $request) use ($labels) {
        if (str_contains($request->url(), '/loki/api/v1/labels')) {
            return $labels === null
                ? Http::response('labels unavailable', 500)
                : Http::response(['status' => 'success', 'data' => $labels]);
        }

        return Http::response(lokiStreams([]));
    });
}

/** How many times the label names API was asked. */
function labelLookups(): int
{
    return collect(Http::recorded())->filter(fn (array $pair): bool => str_contains($pair[0]->url(), '/loki/api/v1/labels'))->count();
}

it('puts the environment in the stream selector where Loki indexes it', function (): void {
    lokiIndexing(['deployment_environment_name', 'service_name']);

    $this->getJson(apiUrl('explore/logs', ['env' => 'prod', 'q' => 'timeout']))->assertOk();
    $this->getJson(apiUrl('explore/logs', ['service' => 'shop', 'env' => 'prod']))->assertOk();

    expect(sentLogql())->toBe([
        '{deployment_environment_name="prod"} |= "timeout"',
        '{service_name="shop",deployment_environment_name="prod"}',
    ]);
});

it('keeps the environment a pipeline filter where it is structured metadata', function (): void {
    lokiIndexing(['service_name']);

    $this->getJson(apiUrl('explore/logs', ['service' => 'shop', 'env' => 'prod']))->assertOk();

    expect(sentLogql())->toBe(['{service_name="shop"} | deployment_environment_name="prod"']);
});

it('keeps the pipeline filter when Loki cannot list its labels, and asks again next time', function (): void {
    lokiIndexing(null);

    $this->getJson(apiUrl('explore/logs', ['service' => 'shop', 'env' => 'prod']))->assertOk();
    app()->forgetScopedInstances(); // the next request
    $this->getJson(apiUrl('explore/logs', ['service' => 'shop', 'env' => 'prod']))->assertOk();

    expect(sentLogql())->each->toBe('{service_name="shop"} | deployment_environment_name="prod"')
        ->and(labelLookups())->toBe(2);
});

it('asks Loki which labels it indexes once, not on every query', function (): void {
    lokiIndexing(['deployment_environment_name', 'service_name']);

    $this->getJson(apiUrl('explore/logs', ['env' => 'prod']))->assertOk();
    app()->forgetScopedInstances(); // the next request
    $this->getJson(apiUrl('explore/logs', ['env' => 'prod']))->assertOk();

    expect(labelLookups())->toBe(1);
});

it('holds an environment lock in the stream selector as well', function (): void {
    lokiIndexing(['deployment_environment_name', 'service_name']);
    config()->set('telemetry-ui.scope.lock.environments', ['prod']);

    $this->getJson(apiUrl('explore/logs', ['env' => 'staging']))->assertOk();

    expect(sentLogql())->each->not->toContain('staging');
});
