<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
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

/**
 * Every panel on a detail page, fetched with the entity param, concatenated —
 * what the reader sees on that page.
 *
 * @param  array<string, string>  $params
 */
function detailPage(string $page, array $params): string
{
    $panels = (array) test()->getJson(apiUrl('pages/'.$page))->assertOk()->json('panels');

    expect($panels)->not->toBeEmpty();

    return implode("\n", array_map(
        fn (array $panel): string => (string) test()->getJson(panelUrl($panel['id'], [...$params, '_page' => $page]))->assertOk()->getContent(),
        $panels,
    ));
}

it('renders the job detail page scoped to the job', function (): void {
    expect(detailPage('job-detail', ['job' => 'SendReport']))->toContain('SendReport')->toContain('All jobs');

    Http::assertSent(fn ($r): bool => str_contains(rawurldecode($r->url()), 'job_name="SendReport"'));
});

it('renders the queue detail page scoped to the queue', function (): void {
    expect(detailPage('queue-detail', ['queue' => 'high']))->toContain('high')->toContain('All queues');

    Http::assertSent(fn ($r): bool => str_contains(rawurldecode($r->url()), 'queue="high"'));
});

it('renders the exception detail page scoped to the class', function (): void {
    expect(detailPage('exception-detail', ['exception' => 'RuntimeException']))->toContain('RuntimeException')->toContain('All exceptions');

    Http::assertSent(fn ($r): bool => str_contains(rawurldecode($r->url()), 'exception="RuntimeException"'));
});

it('renders the machine detail page scoped to the host_name', function (): void {
    expect(detailPage('host-detail', ['host' => 'web-3']))
        ->toContain('web-3')
        ->toContain('All hosts')
        ->toContain('CPU load average')
        ->toContain('Services on this host');

    Http::assertSent(fn ($r): bool => str_contains(rawurldecode($r->url()), 'host_name="web-3"'));
});

it('renders the outgoing host detail page scoped to the host', function (): void {
    expect(detailPage('outgoing-detail', ['host' => 'api.stripe.com']))->toContain('api.stripe.com')->toContain('All hosts');

    Http::assertSent(fn ($r): bool => str_contains(rawurldecode($r->url()), 'server_address="api.stripe.com"'));
});

it('renders the hosts overview page and filters by host', function (): void {
    expect(detailPage('hosts', []))
        ->toContain('web-1')        // from the faked host_name label
        ->toContain('host.name');   // row links filter traces by host
});
