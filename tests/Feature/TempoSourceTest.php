<?php

declare(strict_types=1);

use Cbox\TelemetryUi\Connectors\ApiClient;
use Cbox\TelemetryUi\Connectors\Tempo\TempoSource;
use Cbox\TelemetryUi\Queries\Ir\TraceQuery;
use Cbox\TelemetryUi\Queries\Results\SpanKind;
use Illuminate\Support\Facades\Http;

function tempo(): TempoSource
{
    return new TempoSource(new ApiClient('http://tempo.test:3200'));
}

it('searches traces with traceql', function (): void {
    Http::fake([
        'tempo.test:3200/api/search*' => Http::response([
            'traces' => [
                [
                    'traceID' => '0af7651916cd43dd8448eb211c80319c',
                    'rootServiceName' => 'checkout',
                    'rootTraceName' => 'POST /orders',
                    'startTimeUnixNano' => '1735689600000000000',
                    'durationMs' => 812,
                ],
            ],
            'metrics' => ['inspectedTraces' => 120],
        ]),
    ]);

    $results = tempo()->search(
        TraceQuery::raw('{ resource.service.name = "checkout" && duration > 500ms }'),
        new DateTimeImmutable('@1735686000'),
        new DateTimeImmutable('@1735689600'),
        limit: 5,
    );

    expect($results)->toHaveCount(1)
        ->and($results[0]->traceId)->toBe('0af7651916cd43dd8448eb211c80319c')
        ->and($results[0]->rootServiceName)->toBe('checkout')
        ->and($results[0]->rootTraceName)->toBe('POST /orders')
        ->and($results[0]->durationMs)->toBe(812.0)
        ->and($results[0]->startedAt->getTimestamp())->toBe(1735689600);

    Http::assertSent(fn ($request): bool => requestQuery($request)['q'] === '{ resource.service.name = "checkout" && duration > 500ms }'
        && requestQuery($request)['limit'] === '5');
});

it('fetches a full trace and flattens otlp spans', function (): void {
    Http::fake([
        'tempo.test:3200/api/traces/*' => Http::response([
            'batches' => [
                [
                    'resource' => [
                        'attributes' => [
                            ['key' => 'service.name', 'value' => ['stringValue' => 'checkout']],
                        ],
                    ],
                    'scopeSpans' => [
                        [
                            'spans' => [
                                [
                                    'spanId' => 'aaaa000000000001',
                                    'name' => 'POST /orders',
                                    'kind' => 'SPAN_KIND_SERVER',
                                    'startTimeUnixNano' => '1735689600000000000',
                                    'endTimeUnixNano' => '1735689600812000000',
                                    'attributes' => [
                                        ['key' => 'http.route', 'value' => ['stringValue' => 'orders']],
                                        ['key' => 'http.response.status_code', 'value' => ['intValue' => '500']],
                                        ['key' => 'enduser.id', 'value' => ['intValue' => '7']],
                                    ],
                                    'status' => ['code' => 'STATUS_CODE_ERROR'],
                                ],
                                [
                                    'spanId' => 'aaaa000000000002',
                                    'parentSpanId' => 'aaaa000000000001',
                                    'name' => 'db.query',
                                    'kind' => 3,
                                    'startTimeUnixNano' => '1735689600100000000',
                                    'endTimeUnixNano' => '1735689600180000000',
                                    'attributes' => [
                                        ['key' => 'db.query.text', 'value' => ['stringValue' => 'select * from orders']],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]),
    ]);

    $trace = tempo()->trace('0af7651916cd43dd8448eb211c80319c');

    expect($trace->spans)->toHaveCount(2)
        ->and($trace->root()?->name)->toBe('POST /orders')
        ->and($trace->root()?->kind)->toBe(SpanKind::Server)
        ->and($trace->root()?->serviceName)->toBe('checkout')
        ->and($trace->root()?->hasError)->toBeTrue()
        ->and($trace->root()?->attributes['http.response.status_code'])->toBe(500)
        ->and($trace->root()?->attributes['enduser.id'])->toBe(7)
        ->and($trace->hasError())->toBeTrue()
        ->and($trace->durationMs())->toBe(812.0);

    $child = $trace->children($trace->root());

    expect($child)->toHaveCount(1)
        ->and($child[0]->name)->toBe('db.query')
        ->and($child[0]->kind)->toBe(SpanKind::Client)
        ->and($child[0]->durationMs())->toBe(80.0);
});

it('supports the v2 wrapped trace body', function (): void {
    Http::fake([
        'tempo.test:3200/api/traces/*' => Http::response([
            'trace' => [
                'resourceSpans' => [
                    [
                        'resource' => ['attributes' => [['key' => 'service.name', 'value' => ['stringValue' => 'billing']]]],
                        'scopeSpans' => [
                            ['spans' => [[
                                'spanId' => 'bbbb000000000001',
                                'name' => 'queue.process',
                                'kind' => 'SPAN_KIND_CONSUMER',
                                'startTimeUnixNano' => '1',
                                'endTimeUnixNano' => '2',
                            ]]],
                        ],
                    ],
                ],
            ],
        ]),
    ]);

    $trace = tempo()->trace('abc123');

    expect($trace->spans)->toHaveCount(1)
        ->and($trace->spans[0]->serviceName)->toBe('billing')
        ->and($trace->spans[0]->kind)->toBe(SpanKind::Consumer);
});

it('lists tag values from the v2 endpoint', function (): void {
    Http::fake([
        'tempo.test:3200/api/v2/search/tag/*' => Http::response([
            'tagValues' => [
                ['type' => 'string', 'value' => 'checkout'],
                ['type' => 'string', 'value' => 'billing'],
            ],
        ]),
    ]);

    $values = tempo()->tagValues('resource.service.name');

    expect($values)->toBe(['checkout', 'billing']);
});
