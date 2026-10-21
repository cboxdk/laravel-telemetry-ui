<?php

declare(strict_types=1);

use Cbox\TelemetryUi\Support\DisplayTime;
use Illuminate\Support\Facades\Http;

function fakeOneRequestAt(int $at): void
{
    Http::fake([
        'tempo.test:3200/api/search*' => Http::response([
            'traces' => [
                ['traceID' => '1111111111111111aaaaaaaaaaaaaaaa', 'rootServiceName' => 'demo', 'rootTraceName' => 'GET /orders', 'startTimeUnixNano' => (string) ($at * 1_000_000_000), 'durationMs' => 120,
                    'spanSets' => [['spans' => [['spanID' => 'a1', 'name' => 'GET /orders', 'startTimeUnixNano' => (string) ($at * 1_000_000_000), 'durationNanos' => '120000000', 'attributes' => [
                        ['key' => 'http.request.method', 'value' => ['stringValue' => 'GET']],
                        ['key' => 'http.response.status_code', 'value' => ['intValue' => '200']],
                    ]]]]]],
            ],
        ]),
        'prometheus.test:9090/*' => Http::response(['status' => 'success', 'data' => ['resultType' => 'vector', 'result' => []]]),
        'loki.test:3100/*' => Http::response(['status' => 'success', 'data' => ['resultType' => 'streams', 'result' => []]]),
    ]);
}

function clockIn(int $at, string $zone): string
{
    return (new DateTimeImmutable('@'.$at))->setTimezone(new DateTimeZone($zone))->format('H:i:s');
}

it('shows times in the zone the viewer sends, not the server\'s', function (): void {
    $at = time() - 5;
    fakeOneRequestAt($at);

    $this->withHeader('X-Timezone', 'Asia/Tokyo')
        ->getJson(panelUrl('request-log', ['period' => '1h']))
        ->assertOk()
        ->assertJsonPath('rows.0.time.v', clockIn($at, 'Asia/Tokyo'))
        // The sort key stays the instant itself.
        ->assertJsonPath('rows.0.time.raw', $at);
});

it('falls back to the host\'s default zone, then the app\'s', function (): void {
    $at = time() - 5;
    fakeOneRequestAt($at);

    config()->set('telemetry-ui.timezone', 'America/New_York');
    $this->getJson(panelUrl('request-log', ['period' => '1h']))
        ->assertJsonPath('rows.0.time.v', clockIn($at, 'America/New_York'));

    app()->forgetScopedInstances();
    config()->set('telemetry-ui.timezone', null);
    $this->getJson(panelUrl('request-log', ['period' => '1h']))
        ->assertJsonPath('rows.0.time.v', clockIn($at, (string) config('app.timezone')));
});

it('ignores a zone it does not know, rather than failing the panel', function (string $sent): void {
    $at = time() - 5;
    fakeOneRequestAt($at);
    config()->set('telemetry-ui.timezone', 'Europe/Copenhagen');

    $this->withHeader('X-Timezone', $sent)
        ->getJson(panelUrl('request-log', ['period' => '1h']))
        ->assertOk()
        ->assertJsonPath('rows.0.time.v', clockIn($at, 'Europe/Copenhagen'));
})->with(['+02:00', 'CEST', 'Mars/Olympus_Mons', '', '<script>']);

it('knows the old zone names browsers still report', function (): void {
    expect(DisplayTime::zone('Asia/Calcutta')?->getName())->toBe('Asia/Calcutta')
        ->and(DisplayTime::zone('UTC')?->getName())->toBe('UTC')
        ->and(DisplayTime::zone('Europe/Nowhere'))->toBeNull();
});

it('formats across daylight saving by the zone\'s rules', function (): void {
    $copenhagen = new DisplayTime(new DateTimeZone('Europe/Copenhagen'));

    // 12:00 UTC is 13:00 in winter and 14:00 in summer there.
    expect($copenhagen->format(1767268800, 'H:i'))->toBe('13:00')   // 2026-01-01
        ->and($copenhagen->format(1782907200, 'H:i'))->toBe('14:00'); // 2026-07-01
});

it('dates a time that did not fall today in the viewer\'s zone', function (): void {
    $zone = new DisplayTime(new DateTimeZone('UTC'));

    expect($zone->stamp(time()))->toMatch('/^\d\d:\d\d:\d\d$/')
        ->and($zone->stamp(1767268800))->toBe('Jan 1 12:00');
});

it('tells the SPA the host\'s default zone, and null when it is the browser\'s', function (): void {
    Http::fake(['*' => Http::response(['status' => 'success', 'data' => []])]);

    $boot = function (): array {
        $html = (string) $this->get('/telemetry-ui')->assertOk()->getContent();
        preg_match('~<script type="application/json" id="telemetry-ui-boot">(.*?)</script>~s', $html, $m);

        return (array) json_decode($m[1] ?? '', true);
    };

    expect($boot())->toHaveKey('timezone', null);

    config()->set('telemetry-ui.timezone', 'UTC');
    expect($boot()['timezone'])->toBe('UTC');

    config()->set('telemetry-ui.timezone', 'not a zone');
    expect($boot()['timezone'])->toBeNull();
});
