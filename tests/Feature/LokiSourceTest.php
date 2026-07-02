<?php

declare(strict_types=1);

use Cbox\TelemetryUi\Connectors\ApiClient;
use Cbox\TelemetryUi\Connectors\Loki\LokiSource;
use Cbox\TelemetryUi\Connectors\SourceException;
use Illuminate\Support\Facades\Http;

function loki(): LokiSource
{
    return new LokiSource(new ApiClient('http://loki.test:3100'));
}

it('queries log streams and returns entries in ascending order', function (): void {
    Http::fake([
        'loki.test:3100/loki/api/v1/query_range*' => Http::response([
            'status' => 'success',
            'data' => [
                'resultType' => 'streams',
                'result' => [
                    [
                        'stream' => ['service_name' => 'checkout', 'level' => 'error'],
                        'values' => [
                            ['1735689660000000000', 'Payment failed for order 42'],
                            ['1735689600000000000', 'Charge declined'],
                        ],
                    ],
                    [
                        'stream' => ['service_name' => 'checkout', 'level' => 'info'],
                        'values' => [
                            ['1735689630000000000', 'Order 43 created'],
                        ],
                    ],
                ],
            ],
        ]),
    ]);

    $entries = loki()->query(
        '{service_name="checkout"}',
        new DateTimeImmutable('@1735686000'),
        new DateTimeImmutable('@1735689600'),
        limit: 50,
    );

    expect($entries)->toHaveCount(3)
        ->and($entries[0]->line)->toBe('Charge declined')
        ->and($entries[1]->line)->toBe('Order 43 created')
        ->and($entries[2]->line)->toBe('Payment failed for order 42')
        ->and($entries[0]->labels)->toBe(['service_name' => 'checkout', 'level' => 'error'])
        ->and($entries[0]->timestamp()->getTimestamp())->toBe(1735689600);

    // Loki expects nanosecond timestamps and a direction.
    Http::assertSent(function ($request): bool {
        $query = requestQuery($request);

        return $query['start'] === '1735686000000000000'
            && $query['end'] === '1735689600000000000'
            && $query['direction'] === 'backward'
            && $query['limit'] === '50';
    });
});

it('rejects metric-style logql results', function (): void {
    Http::fake([
        'loki.test:3100/*' => Http::response([
            'status' => 'success',
            'data' => ['resultType' => 'matrix', 'result' => []],
        ]),
    ]);

    loki()->query('rate({service_name="checkout"}[5m])', new DateTimeImmutable('-1 hour'), new DateTimeImmutable);
})->throws(SourceException::class);

it('lists label values', function (): void {
    Http::fake([
        'loki.test:3100/loki/api/v1/label/service_name/values*' => Http::response([
            'status' => 'success',
            'data' => ['billing', 'checkout'],
        ]),
    ]);

    expect(loki()->labelValues('service_name'))->toBe(['billing', 'checkout']);
});
