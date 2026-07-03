<?php

declare(strict_types=1);

use Cbox\TelemetryUi\Connectors\Tempo\OtlpAttributes;

it('flattens every OTLP value type', function (): void {
    $parsed = OtlpAttributes::parse([
        ['key' => 'http.route', 'value' => ['stringValue' => '/orders']],
        ['key' => 'http.status', 'value' => ['intValue' => '200']],
        ['key' => 'sampling.ratio', 'value' => ['doubleValue' => 0.25]],
        ['key' => 'error', 'value' => ['boolValue' => true]],
        ['key' => 'http.tags', 'value' => ['arrayValue' => ['values' => [
            ['stringValue' => 'a'], ['stringValue' => 'b'],
        ]]]],
        ['key' => 'db', 'value' => ['kvlistValue' => ['values' => [
            ['key' => 'system', 'value' => ['stringValue' => 'mysql']],
            ['key' => 'rows', 'value' => ['intValue' => '5']],
        ]]]],
    ]);

    expect($parsed)->toBe([
        'http.route' => '/orders',
        'http.status' => 200,
        'sampling.ratio' => 0.25,
        'error' => true,
        'http.tags' => ['a', 'b'],
        'db' => ['system' => 'mysql', 'rows' => 5],
    ]);
});

it('coerces types faithfully and tolerates unknown shapes', function (): void {
    expect(OtlpAttributes::value(['boolValue' => false]))->toBeFalse()
        ->and(OtlpAttributes::value(['intValue' => '42']))->toBe(42)
        ->and(OtlpAttributes::value(['doubleValue' => '1.5']))->toBe(1.5)
        ->and(OtlpAttributes::value([]))->toBeNull()
        ->and(OtlpAttributes::value(['weirdValue' => 'x']))->toBeNull()
        // A nested array of kvlists round-trips recursively.
        ->and(OtlpAttributes::value(['arrayValue' => ['values' => [
            ['kvlistValue' => ['values' => [['key' => 'k', 'value' => ['stringValue' => 'v']]]]],
        ]]]))->toBe([['k' => 'v']]);
});

it('skips attributes without a string key', function (): void {
    $parsed = OtlpAttributes::parse([
        ['value' => ['stringValue' => 'orphan']],
        ['key' => 42, 'value' => ['stringValue' => 'numeric key']],
        ['key' => 'ok', 'value' => ['stringValue' => 'kept']],
    ]);

    expect($parsed)->toBe(['ok' => 'kept']);
});
