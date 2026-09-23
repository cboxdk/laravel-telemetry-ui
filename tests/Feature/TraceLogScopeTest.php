<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;

/** A trace over one or two services, with an exception record for it in Loki. */
function fakeTraceOver(array $services): void
{
    Http::fake([
        'tempo.test:3200/api/traces/*' => Http::response(['batches' => array_map(static fn (string $service, int $i): array => [
            'resource' => ['attributes' => [['key' => 'service.name', 'value' => ['stringValue' => $service]]]],
            'scopeSpans' => [['spans' => [[
                'spanId' => 'a'.$i,
                ...($i > 0 ? ['parentSpanId' => 'a0'] : []),
                'name' => 'GET /orders',
                'kind' => 'SPAN_KIND_SERVER',
                'startTimeUnixNano' => '1735689600000000000',
                'endTimeUnixNano' => '1735689601000000000',
            ]]]],
        ], $services, array_keys($services))]),
        'loki.test:3100/*' => Http::response(lokiStreams([])),
        '*' => Http::response(['status' => 'success', 'data' => ['resultType' => 'vector', 'result' => []]]),
    ]);
}

beforeEach(fn () => Gate::define('viewTelemetryUi', fn (?object $user = null, ?string $page = null): bool => true));

it('looks for a trace\'s exception records in that trace\'s own service', function (): void {
    fakeTraceOver(['shop']);

    $this->getJson(apiUrl('traces/abc123abc123abc123abc123abc123ab'))->assertOk();

    // Not {service_name=~".+"}: that makes Loki read every service's lines.
    expect(collect(sentLogql())->filter(fn (string $q): bool => str_contains($q, 'exception_group')))
        ->not->toBeEmpty()
        ->each->toContain('service_name="shop"');
});

it('uses an alternation when the trace crosses services', function (): void {
    fakeTraceOver(['shop', 'billing']);

    $this->getJson(apiUrl('traces/abc123abc123abc123abc123abc123ab'))->assertOk();

    expect(collect(sentLogql())->contains(fn (string $q): bool => str_contains($q, 'service_name=~"shop|billing"')))->toBeTrue();
});
