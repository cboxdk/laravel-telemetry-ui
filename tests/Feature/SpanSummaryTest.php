<?php

declare(strict_types=1);

use Cbox\TelemetryUi\Queries\Results\Span;
use Cbox\TelemetryUi\Queries\Results\SpanKind;

function span(array $attributes): Span
{
    return new Span('a', null, 'span', 'svc', SpanKind::Internal, 0, 1_000_000, $attributes, false);
}

it('summarizes a database query span', function (): void {
    expect(span(['db.query.text' => 'select *  from   orders where id = ?'])->summary())
        ->toBe('select * from orders where id = ?');
});

it('summarizes an http span with method, route and status', function (): void {
    expect(span([
        'http.request.method' => 'POST',
        'http.route' => '/orders',
        'http.response.status_code' => 201,
    ])->summary())->toBe('POST /orders → 201');
});

it('summarizes queue, cache and view spans', function (): void {
    expect(span(['messaging.destination.name' => 'default'])->summary())->toBe('queue: default')
        ->and(span(['cache.key' => 'user:7'])->summary())->toBe('key: user:7')
        ->and(span(['view.name' => 'emails.welcome'])->summary())->toBe('view: emails.welcome');
});

it('truncates long queries', function (): void {
    $long = 'select '.str_repeat('col, ', 40).'x from t';

    expect(mb_strlen((string) span(['db.query.text' => $long])->summary()))->toBeLessThanOrEqual(91);
});

it('returns null when nothing telling is present', function (): void {
    expect(span(['internal.flag' => true])->summary())->toBeNull();
});
