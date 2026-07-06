<?php

declare(strict_types=1);

use Cbox\TelemetryUi\Analysis\RequestReport;
use Cbox\TelemetryUi\Cards\Builtin\TraceSearch;
use Cbox\TelemetryUi\Queries\Results\Span;
use Cbox\TelemetryUi\Queries\Results\SpanKind;
use Cbox\TelemetryUi\Queries\Results\Trace;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

function reportSpan(string $id, string $name, SpanKind $kind, array $attributes, int $ms = 10, ?string $parent = 'root'): Span
{
    return new Span(
        spanId: $id,
        parentSpanId: $parent,
        name: $name,
        serviceName: 'demo',
        kind: $kind,
        startNano: 1_000_000_000,
        endNano: 1_000_000_000 + $ms * 1_000_000,
        attributes: $attributes,
        hasError: false,
    );
}

it('tells the request as readable sections instead of raw spans', function (): void {
    $root = new Span(
        spanId: 'root', parentSpanId: null, name: 'GET /orders', serviceName: 'demo',
        kind: SpanKind::Server, startNano: 1_000_000_000, endNano: 2_000_000_000,
        attributes: [
            'http.request.method' => 'GET',
            'http.route' => '/orders',
            'url.path' => '/orders',
            'http.response.status_code' => 200,
            'client.address' => '203.0.113.9',
            'user_agent.original' => 'Mozilla/5.0',
            'enduser.id' => 7,
            'db.query.count' => 4,
            'db.query.time_ms' => 38.5,
            'cache.event.count' => 3,
            'http.request.header.accept' => 'text/html',
            'http.response.header.content-type' => 'text/html; charset=UTF-8',
        ],
        hasError: false,
    );

    $trace = new Trace('abc123abc123abc123abc123abc123ab', [
        $root,
        reportSpan('s1', 'db.query', SpanKind::Client, ['db.query.text' => 'select * from users where id = ?', 'db.namespace' => 'mysql'], 12),
        reportSpan('s2', 'db.query', SpanKind::Client, ['db.query.text' => 'select * from users where id = ?', 'db.namespace' => 'mysql'], 9),
        reportSpan('s3', 'cache.miss', SpanKind::Client, ['cache.key' => 'shop:promo'], 1),
        reportSpan('s4', 'cache.hit', SpanKind::Client, ['cache.key' => 'shop:count'], 1),
        reportSpan('s5', 'redis GET', SpanKind::Client, ['db.operation.name' => 'GET shop:count'], 2),
        reportSpan('s6', 'HTTP POST', SpanKind::Client, ['http.url' => 'https://api.stripe.com/v1/charges', 'http.response.status_code' => 201], 240),
        reportSpan('s7', 'queue publish', SpanKind::Producer, ['messaging.destination.name' => 'App\\Jobs\\Ship'], 3),
        reportSpan('s8', 'view', SpanKind::Internal, ['view.name' => 'orders.index'], 20),
    ]);

    $report = RequestReport::from($trace);

    // The request facts.
    expect($report['request']['method'])->toBe('GET')
        ->and($report['request']['route'])->toBe('/orders')
        ->and($report['request']['ip'])->toBe('203.0.113.9')
        ->and($report['request']['user'])->toBe('#7')
        ->and($report['request']['status'])->toBe('200');

    // Captured headers, split by direction.
    expect($report['requestHeaders'])->toBe(['accept' => 'text/html'])
        ->and($report['responseHeaders'])->toBe(['content-type' => 'text/html; charset=UTF-8']);

    // Cost totals off the root tallies.
    expect(collect($report['totals'])->pluck('label'))->toContain('queries', 'query time', 'cache ops');

    // Sections, slowest first — and the N+1 named.
    expect($report['db']['items'])->toHaveCount(2)
        ->and($report['db']['items'][0]['durationMs'])->toBe(12.0)
        ->and($report['db']['duplicates'])->toBe(['select * from users where id = ?' => 2]);

    expect($report['cache']['summary'])->toBe(['miss' => 1, 'hit' => 1])
        ->and($report['redis'])->toHaveCount(1)
        ->and($report['outgoing'][0]['detail'])->toBe('https://api.stripe.com/v1/charges')
        ->and($report['outgoing'][0]['name'])->toBe('201')
        ->and($report['queued'][0]['detail'])->toBe('App\\Jobs\\Ship')
        ->and($report['views'][0]['detail'])->toBe('orders.index');
});

it('adapts to a job trace with no http facts', function (): void {
    $root = new Span(
        spanId: 'root', parentSpanId: null, name: 'job App\\Jobs\\Ship', serviceName: 'demo',
        kind: SpanKind::Consumer, startNano: 1_000_000_000, endNano: 2_000_000_000,
        attributes: ['messaging.destination.name' => 'App\\Jobs\\Ship'],
        hasError: false,
    );

    $report = RequestReport::from(new Trace('abc123abc123abc123abc123abc123ab', [$root]));

    expect($report['request'])->toBe(['job' => 'App\\Jobs\\Ship'])
        ->and($report['db']['items'])->toBe([]);
});

it('composes purpose-built filters into traceql', function (): void {
    Http::fake(['tempo.test:3200/api/search*' => Http::response(['traces' => []])]);

    Livewire::test(TraceSearch::class)
        ->set('statusCode', '5xx')
        ->set('path', '/checkout')
        ->set('ip', '203.0.113.9');

    Http::assertSent(function ($request): bool {
        $q = rawurldecode(requestQuery($request)['q'] ?? '');

        return str_contains($q, 'span.http.response.status_code >= 500')
            && str_contains($q, 'span.http.response.status_code < 600');
    });

    Http::assertSent(function ($request): bool {
        $q = rawurldecode(requestQuery($request)['q'] ?? '');

        return str_contains($q, 'span.url.path =~')
            && str_contains($q, 'checkout')
            && str_contains($q, 'span.client.address = "203.0.113.9"');
    });
});
