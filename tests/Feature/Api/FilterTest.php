<?php

declare(strict_types=1);

use Cbox\TelemetryUi\Http\Api\Filter;

it('parses every operator', function (string $raw, string $key, string $op, string $value): void {
    $filter = Filter::parse($raw);

    expect($filter)->not->toBeNull()
        ->and($filter?->key)->toBe($key)
        ->and($filter?->op)->toBe($op)
        ->and($filter?->value)->toBe($value)
        ->and($filter?->toString())->toBe($key.$op.$value);
})->with([
    ['hubhus.customer_id=8655', 'hubhus.customer_id', '=', '8655'],
    ['http.request.method!=GET', 'http.request.method', '!=', 'GET'],
    ['http.route=~/orders/.*', 'http.route', '=~', '/orders/.*'],
    ['http.route!~/admin.*', 'http.route', '!~', '/admin.*'],
    ['http.response.status_code>=500', 'http.response.status_code', '>=', '500'],
    ['http.response.status_code<=499', 'http.response.status_code', '<=', '499'],
    ['duration>250ms', 'duration', '>', '250ms'],
    ['duration<1.5s', 'duration', '<', '1.5s'],
    ['db.query.text=', 'db.query.text', '=', ''],
]);

it('takes the first operator after the key, so values may hold anything', function (string $raw, string $key, string $op, string $value): void {
    $filter = Filter::parse($raw);

    expect([$filter?->key, $filter?->op, $filter?->value])->toBe([$key, $op, $value]);
})->with([
    'equals in value' => ['url.query=a=b&c=d', 'url.query', '=', 'a=b&c=d'],
    'operators in value' => ['note=x!=y>=z<w~v', 'note', '=', 'x!=y>=z<w~v'],
    'url with colon and slashes' => ['http.url=https://app.test:8443/a/b?c=1', 'http.url', '=', 'https://app.test:8443/a/b?c=1'],
    'regex with anchors' => ['url.full=~^https?://[^/]+/api/(v1|v2)$', 'url.full', '=~', '^https?://[^/]+/api/(v1|v2)$'],
    'negated regex, value starting with =' => ['k!~=x', 'k', '!~', '=x'],
    'ge beats gt' => ['n>=5', 'n', '>=', '5'],
    'value with spaces and unicode' => ['view.name=Ordre oversigt – æøå', 'view.name', '=', 'Ordre oversigt – æøå'],
    'multi-line value' => ["msg=a\nb", 'msg', '=', "a\nb"],
    'route braces' => ['http.route=/orders/{order}', 'http.route', '=', '/orders/{order}'],
]);

it('rejects strings that are not a filter', function (string $raw): void {
    expect(Filter::parse($raw))->toBeNull();
})->with([
    'no operator' => ['http.route'],
    'no key' => ['=value'],
    'space in key' => ['http route=/x'],
    'empty' => [''],
    'bare operator' => ['!='],
]);

it('parses a list, a single string, or nothing', function (): void {
    expect(array_map(fn (Filter $f): string => $f->toString(), Filter::parseAll(['a=1', 'junk', 42, null, 'b!=2'])))->toBe(['a=1', 'b!=2'])
        ->and(Filter::parseAll('a=1'))->toHaveCount(1)
        ->and(Filter::parseAll(null))->toBe([])
        ->and(Filter::parseAll(['nested' => ['a=1']]))->toBe([]);
});

it('knows which operators negate', function (): void {
    expect(Filter::parse('a!=1')?->negated())->toBeTrue()
        ->and(Filter::parse('a!~1')?->negated())->toBeTrue()
        ->and(Filter::parse('a=1')?->negated())->toBeFalse()
        ->and(Filter::parse('a=~1')?->negated())->toBeFalse()
        ->and(Filter::parse('a>1')?->negated())->toBeFalse();
});

it('matches values read-side', function (string $raw, mixed $actual, bool $expected): void {
    expect(Filter::parse($raw)?->matches($actual))->toBe($expected);
})->with([
    ['a=8655', '8655', true],
    ['a=8655', 8655, true],
    ['a=8655', '86550', false],
    ['a!=GET', 'POST', true],
    ['a!=GET', 'GET', false],
    ['a=~^/orders', '/orders/17', true],
    ['a=~^/orders', '/users', false],
    ['a!~^/admin', '/orders', true],
    ['a!~^/admin', '/admin/x', false],
    ['a=~a~b', 'xa~by', true],             // the regex delimiter in a pattern is escaped
    ['a=~(', 'anything', false],            // an invalid pattern matches nothing, no warning
    ['a!~(', 'anything', true],
    ['a>100', '250', true],
    ['a>100', 100, false],
    ['a>=100', 100.0, true],
    ['a<1.5', '1.25', true],
    ['a<=0', '-1', true],
    ['a>100', 'lots', false],               // numeric ops never match non-numbers
    ['a>lots', '250', false],
    ['a=', null, true],                     // missing reads as empty
    ['a=true', true, false],                // bools are scalars cast to "1"
    ['a=1', true, true],
    ['a=["x"]', ['x'], true],               // non-scalars compare as json
]);
