<?php

declare(strict_types=1);

use Cbox\TelemetryUi\Facades\TelemetryUi;
use Cbox\TelemetryUi\TelemetryUiManager;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

const FAILING_TRACE = 'ffff0000ffff0000ffff0000ffff0002';

/**
 * Five /orders requests: customer 8655 carries all three failures (a 500 and
 * two 422s); a deploy lands shortly before the first failure, and a Loki
 * exception record belongs to the failing trace.
 */
function fakeEntityBackends(): void
{
    $now = time();
    $span = fn (string $id, int $ago, float $ms, int $status, string $customer): array => tempoHit($id, 'GET /orders', $now - $ago, $ms, [
        'http.request.method' => 'GET', 'http.route' => '/orders', 'http.response.status_code' => $status, 'billing.customer_id' => $customer, 'user.id' => '7',
    ]);

    Http::fake([
        'tempo.test:3200/api/search*' => Http::response(['traces' => [
            $span('ffff0000ffff0000ffff0000ffff0001', 500, 40.0, 200, '9001'),
            $span(FAILING_TRACE, 300, 2400.0, 500, '8655'),
            $span('ffff0000ffff0000ffff0000ffff0003', 200, 60.0, 422, '8655'),
            $span('ffff0000ffff0000ffff0000ffff0004', 100, 55.0, 422, '8655'),
            $span('ffff0000ffff0000ffff0000ffff0005', 50, 30.0, 200, '9001'),
        ]]),
        'loki.test:3100/*' => Http::response(lokiStreams([
            ['stream' => ['service_name' => 'shop', 'exception_group' => 'abc123def456', 'exception_type' => 'App\\Exceptions\\PaymentDeclined', 'exception_message' => 'Card declined', 'trace_id' => FAILING_TRACE], 'values' => [
                [(string) (($now - 300) * 1_000_000_000), 'exception'],
            ]],
            // An exception from a trace outside this entity: not correlated.
            ['stream' => ['service_name' => 'shop', 'exception_group' => '999999999999', 'exception_type' => 'Unrelated', 'trace_id' => 'aaaa0000aaaa0000aaaa0000aaaa9999'], 'values' => [
                [(string) (($now - 250) * 1_000_000_000), 'exception'],
            ]],
            ['stream' => ['service_name' => 'shop', 'deployment_id' => 'v42'], 'values' => [
                [(string) (($now - 600) * 1_000_000_000), 'app.deployment'],
            ]],
        ])),
        'prometheus.test:9090/*' => Http::response(['status' => 'success', 'data' => ['resultType' => 'vector', 'result' => []]]),
    ]);
}

it('lists every value of an entity type with red', function (): void {
    fakeEntityBackends();

    $response = $this->getJson(apiUrl('entities/route'))
        ->assertOk()
        ->assertJsonPath('entity.type', 'route')
        ->assertJsonPath('entity.key', 'http.route')
        ->assertJsonPath('entity.label', 'Route')
        ->assertJsonPath('entity.custom', false)
        ->assertJsonPath('signal', 'requests')
        ->assertJsonPath('stats.count', 5)
        ->assertJsonPath('sample.size', 5);

    expect($response->json('values.0'))->toMatchArray(['value' => '/orders', 'count' => 5, 'errors' => 1]);

    // Only spans that carry the attribute.
    expect(sentTraceql()[0])->toContain('span.http.route != nil')->toContain('kind = server');
});

it('tells the story of one route', function (): void {
    TelemetryUi::dimension('billing.customer_id', label: 'Customer', group: 'Billing');
    fakeEntityBackends();

    $response = $this->getJson(apiUrl('entities/route/story', ['value' => '/orders']))
        ->assertOk()
        ->assertJsonPath('entity.type', 'route')
        ->assertJsonPath('entity.value', '/orders')
        ->assertJsonPath('entity.linkOut', null)
        ->assertJsonPath('signal', 'requests')
        ->assertJsonPath('where', ['http.route=/orders'])
        ->assertJsonPath('red.count', 5)
        ->assertJsonPath('red.errors', 1);

    expect(sentTraceql()[0])->toContain('span.http.route = "/orders"');

    // Failing = 4xx or 5xx; slowest first.
    expect(array_column($response->json('failing'), 'status'))->toEqualCanonicalizing(['500', '422', '422'])
        ->and($response->json('slowest.0.traceId'))->toBe(FAILING_TRACE)
        ->and($response->json('recent'))->toHaveCount(5);

    // Host-declared dimensions break down first, with failure lift.
    expect($response->json('breakdowns.0.key'))->toBe('billing.customer_id')
        ->and($response->json('breakdowns.0.custom'))->toBeTrue()
        ->and($response->json('breakdowns.0.values.0'))->toMatchArray(['value' => '8655', 'count' => 3, 'failing' => 3])
        ->and($response->json('breakdowns.0.values.0.lift'))->toEqualWithDelta(5 / 3, 0.001);

    // Correlated errors: only groups thrown inside this entity's traces.
    expect($response->json('errors'))->toBe([
        ['group' => 'abc123def456', 'type' => 'App\\Exceptions\\PaymentDeclined', 'message' => 'Card declined', 'count' => 1, 'traceId' => FAILING_TRACE],
    ]);

    expect($response->json('deploys'))->toHaveCount(1);

    $insights = collect($response->json('insights'))->pluck('text')->implode("\n");

    expect($insights)
        ->toContain('60% of 5 failed')
        ->toContain('Failures concentrate on Customer 8655 (3 of 3)')
        ->toContain('Throws App\\Exceptions\\PaymentDeclined (1×)')
        ->toContain('after deploy Deploy v42 — suspect');

    expect(collect($response->json('insights'))->firstWhere('dim')['dim'])->toBe(['key' => 'billing.customer_id', 'value' => '8655'])
        ->and(collect($response->json('insights'))->firstWhere('link')['link'])->toBe(['to' => 'error', 'group' => 'abc123def456']);

    // The request-detail panels, scoped to the route.
    $panels = $response->json('panels');
    $expected = array_map(fn (string $panel): string => $panel::id(), app(TelemetryUiManager::class)->panels('request-detail'));

    expect(array_column($panels, 'id'))->toBe($expected)
        ->and($panels[0]['params'])->toBe(['route' => '/orders', '_page' => 'request-detail']);

    // Raw attributes of a representative span come last.
    expect($response->json('raw'))->toMatchArray(['http.route' => '/orders']);
});

it('tells the story of a host-declared dimension, with a link out', function (): void {
    TelemetryUi::dimension('billing.customer_id', label: 'Customer', group: 'Billing', link: fn (string $id): string => 'https://crm.test/customers/'.$id);
    fakeEntityBackends();

    $response = $this->getJson(apiUrl('entities/billing.customer_id/story', ['value' => '8655']))
        ->assertOk()
        ->assertJsonPath('entity.type', 'billing.customer_id')
        ->assertJsonPath('entity.label', 'Customer')
        ->assertJsonPath('entity.group', 'Billing')
        ->assertJsonPath('entity.custom', true)
        ->assertJsonPath('entity.linksOut', true)
        ->assertJsonPath('entity.linkOut', 'https://crm.test/customers/8655')
        ->assertJsonPath('panels', []);

    // Ids are strings even when they look numeric.
    expect(sentTraceql()[0])->toContain('span.billing.customer_id = "8655"');

    // Its own key is never one of its breakdowns.
    expect(array_column($response->json('breakdowns'), 'key'))->not->toContain('billing.customer_id')->toContain('http.route');
});

it('links out through a url template', function (): void {
    TelemetryUi::dimension('billing.campaign_id', label: 'Campaign', link: 'https://crm.test/campaigns/{value}');
    fakeEntityBackends();

    $this->getJson(apiUrl('entities/billing.campaign_id/story', ['value' => 'spring sale']))
        ->assertOk()
        ->assertJsonPath('entity.linkOut', 'https://crm.test/campaigns/spring%20sale');
});

it('says so plainly when an entity has no spans in the window', function (): void {
    Http::fake([
        'tempo.test:3200/*' => Http::response(['traces' => []]),
        'loki.test:3100/*' => Http::response(lokiStreams([])),
    ]);

    $response = $this->getJson(apiUrl('entities/route/story', ['value' => '/nowhere']))->assertOk();

    // Falls back from server spans to any span before giving up.
    expect(sentTraceql())->toHaveCount(2)
        ->and(sentTraceql()[1])->not->toContain('kind = server')
        ->and($response->json('signal'))->toBe('traces')
        ->and($response->json('insights.0.text'))->toContain('No spans for Route /nowhere');
});

it('404s an unknown entity type', function (): void {
    $this->getJson(apiUrl('entities/nope'))->assertNotFound()->assertJsonPath('error.type', 'not_found');
    $this->getJson(apiUrl('entities/nope/story', ['value' => 'x']))->assertNotFound()->assertJsonPath('error.type', 'not_found');
});

it('422s a story without a value', function (): void {
    $this->getJson(apiUrl('entities/route/story'))->assertStatus(422)->assertJsonPath('error.type', 'invalid');
    $this->getJson(apiUrl('entities/route/story', ['value' => '']))->assertStatus(422);
});

it('answers a typed 502 when the traces backend fails', function (): void {
    Http::fake(['tempo.test:3200/*' => Http::response('down', 503)]);

    $this->getJson(apiUrl('entities/route'))->assertStatus(502)->assertJsonPath('error.type', 'backend');
    $this->getJson(apiUrl('entities/route/story', ['value' => '/orders']))->assertStatus(502)->assertJsonPath('error.type', 'backend');
});

it('counts failed occurrences of a span-level entity from a status = error search', function (): void {
    $now = time() - 60;

    Http::fake(function (Request $request) use ($now) {
        if (str_contains($request->url(), 'loki.test')) {
            return Http::response(lokiStreams([]));
        }

        $q = (string) (requestQuery($request)['q'] ?? '');
        $job = fn (string $trace): array => tempoHit($trace, 'App\\Jobs\\Charge process', $now, 120.0, ['laravel.job.class' => 'App\\Jobs\\Charge']);

        return Http::response(['traces' => str_contains($q, 'status = error') ? [$job('t2')] : [$job('t1'), $job('t2'), $job('t3')]]);
    });

    $story = $this->getJson(apiUrl('entities/job/story', ['value' => 'App\\Jobs\\Charge']))->assertOk()->json();

    expect($story['signal'])->toBe('traces')
        ->and($story['red']['count'])->toBe(3)
        ->and($story['red']['errors'])->toBe(1)
        ->and($story['failing'])->toHaveCount(1)
        ->and($story['insights'][0]['text'])->toContain('failed')
        ->and($story['insights'][0]['text'])->not->toContain('server errors')
        ->and(collect($story['breakdowns'])->firstWhere('key', 'trace.root')['drill'])->toBeFalse();
});

it('reads the exception records of only the services an entity\'s traces ran in', function (): void {
    fakeEntityBackends();

    $this->getJson(apiUrl('entities/route/story', ['value' => '/orders']))->assertOk();

    $exceptionQueries = collect(Http::recorded())
        ->map(fn (array $pair): string => rawurldecode((string) $pair[0]->url()))
        ->filter(fn (string $url): bool => str_contains($url, 'loki.test') && str_contains($url, 'exception_group'));

    // Not {service_name=~".+"}: with no service selected that makes the store
    // read every stream it has, for records only these services can hold.
    expect($exceptionQueries)->not->toBeEmpty()
        ->each->toContain('{service_name="shop"}');
});
