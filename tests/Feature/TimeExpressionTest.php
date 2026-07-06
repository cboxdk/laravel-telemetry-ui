<?php

declare(strict_types=1);

use Cbox\TelemetryUi\Cards\Builtin\JobsOverview;
use Cbox\TelemetryUi\Support\TimeExpression;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

it('parses unix seconds, now, and grafana-style offsets', function (): void {
    $now = new DateTimeImmutable('2026-07-06 12:00:00');

    expect(TimeExpression::parse('1735689600')?->getTimestamp())->toBe(1735689600)
        ->and(TimeExpression::parse('now', $now))->toEqual($now)
        ->and(TimeExpression::parse('now-1h', $now))->toEqual($now->modify('-3600 seconds'))
        ->and(TimeExpression::parse('now-1d', $now))->toEqual($now->modify('-86400 seconds'))
        ->and(TimeExpression::parse('now-2w', $now))->toEqual($now->modify('-1209600 seconds'))
        ->and(TimeExpression::parse('now+30m', $now))->toEqual($now->modify('+1800 seconds'))
        ->and(TimeExpression::parse('now-1M', $now))->toEqual($now->modify('-2592000 seconds'));
});

it('rejects malformed expressions', function (string $value): void {
    expect(TimeExpression::parse($value))->toBeNull();
})->with(['', 'yesterday', 'now-', 'now-1', 'now-1x', '1h', 'now - 1h', '-1h', 'now-1.5h']);

it('labels relative expressions verbatim and unix seconds as dates', function (): void {
    expect(TimeExpression::label('now-1h'))->toBe('now-1h')
        ->and(TimeExpression::label('1735689600'))->toBe(date('d/m H:i', 1735689600));
});

it('drives card ranges from relative from/to like grafana', function (): void {
    Http::fake([
        'prometheus.test:9090/api/v1/query_range*' => Http::response([
            'status' => 'success', 'data' => ['resultType' => 'matrix', 'result' => []],
        ]),
        'prometheus.test:9090/api/v1/query?*' => Http::response([
            'status' => 'success', 'data' => ['resultType' => 'vector', 'result' => []],
        ]),
        'loki.test:3100/*' => Http::response(['status' => 'success', 'data' => ['resultType' => 'streams', 'result' => []]]),
    ]);

    Livewire::withQueryParams(['from' => 'now-2h', 'to' => 'now'])
        ->test(JobsOverview::class)
        ->assertOk();

    // The period total spans exactly the relative window.
    Http::assertSent(function ($request): bool {
        $q = rawurldecode(requestQuery($request)['query'] ?? '');

        return str_contains($q, '[7200s]');
    });
});

it('falls back to the preset period when the expression is invalid', function (): void {
    Http::fake([
        'prometheus.test:9090/*' => Http::response([
            'status' => 'success', 'data' => ['resultType' => 'vector', 'result' => []],
        ]),
        'loki.test:3100/*' => Http::response(['status' => 'success', 'data' => ['resultType' => 'streams', 'result' => []]]),
    ]);

    Livewire::withQueryParams(['from' => 'now-1x', 'to' => 'now', 'period' => '1h'])
        ->test(JobsOverview::class)
        ->assertOk();

    Http::assertSent(function ($request): bool {
        $q = rawurldecode(requestQuery($request)['query'] ?? '');

        return str_contains($q, '[3600s]');
    });
});
