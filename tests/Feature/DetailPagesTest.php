<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Gate::define('viewTelemetryUi', fn (?object $user = null): bool => true);

    Http::fake([
        'prometheus.test:9090/api/v1/query_range*' => Http::response([
            'status' => 'success', 'data' => ['resultType' => 'matrix', 'result' => []],
        ]),
        'prometheus.test:9090/api/v1/query*' => Http::response([
            'status' => 'success',
            'data' => ['resultType' => 'vector', 'result' => [['metric' => ['host_name' => 'web-1'], 'value' => [1735689600, '5']]]],
        ]),
        'tempo.test:3200/*' => Http::response(['traces' => [], 'tagValues' => []]),
        'loki.test:3100/*' => Http::response(['status' => 'success', 'data' => ['resultType' => 'streams', 'result' => []]]),
    ]);
});

it('renders the job detail page scoped to the job', function (): void {
    $this->get('/telemetry-ui/job-detail?job=SendReport')
        ->assertOk()
        ->assertSee('SendReport')
        ->assertSee('All jobs');

    Http::assertSent(fn ($r): bool => str_contains(rawurldecode($r->url()), 'job_name="SendReport"'));
});

it('renders the exception detail page scoped to the class', function (): void {
    $this->get('/telemetry-ui/exception-detail?exception=RuntimeException')
        ->assertOk()
        ->assertSee('RuntimeException')
        ->assertSee('All exceptions');

    Http::assertSent(fn ($r): bool => str_contains(rawurldecode($r->url()), 'exception="RuntimeException"'));
});

it('renders the outgoing host detail page scoped to the host', function (): void {
    $this->get('/telemetry-ui/outgoing-detail?host=api.stripe.com')
        ->assertOk()
        ->assertSee('api.stripe.com')
        ->assertSee('All hosts');

    Http::assertSent(fn ($r): bool => str_contains(rawurldecode($r->url()), 'server_address="api.stripe.com"'));
});

it('renders the hosts overview page and filters by host', function (): void {
    $this->get('/telemetry-ui/hosts')
        ->assertOk()
        ->assertSee('web-1')             // from the faked host_name label
        ->assertSee('host.name', false); // row links filter traces by host (percent-encoded in the href)
});

it('bounds the paths tagValues call with a time window and limit', function (): void {
    $this->get('/telemetry-ui/request-detail?route=/orders&period=1h')->assertOk();

    // The Paths card must not scan all retention: start/end/limit are sent.
    Http::assertSent(fn ($r): bool => str_contains($r->url(), '/search/tag/')
        && str_contains($r->url(), 'start=')
        && str_contains($r->url(), 'end=')
        && str_contains($r->url(), 'limit=100'));
});
