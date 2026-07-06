<?php

declare(strict_types=1);

use Cbox\TelemetryUi\Support\ExceptionFingerprint;
use Cbox\TelemetryUi\TelemetryUiManager;
use Cbox\TelemetryUi\TraceDrawer;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

/**
 * Backend exception records as they land in Loki: line "exception", the
 * fingerprint + structured fields as labels (structured metadata).
 */
function fakeExceptionRecords(): void
{
    Http::fake([
        'loki.test:3100/loki/api/v1/query_range*' => Http::response([
            'status' => 'success',
            'data' => ['resultType' => 'streams', 'result' => [
                [
                    'stream' => [
                        'service_name' => 'checkout',
                        'exception_group' => 'abc123def456',
                        'exception_type' => 'App\\Exceptions\\PaymentDeclined',
                        'exception_message' => 'Card declined by issuer',
                        'exception_file' => 'app/Services/Checkout.php',
                        'exception_line' => '42',
                        'exception_stacktrace' => "#0 app/Services/Checkout.php(42): charge()\n#1 {main}",
                        'exception_source' => "  41| \$card = \$request->card();\n> 42| throw new PaymentDeclined();\n  43| return \$ok;",
                        'trace_id' => '1111111111111111aaaaaaaaaaaaaaaa',
                    ],
                    'values' => [
                        ['1735689600000000000', 'exception'],
                        ['1735603200000000000', 'exception'],
                    ],
                ],
            ]],
        ]),
        'tempo.test:3200/*' => Http::response(['traces' => []]),
    ]);
}

it('opens an exception group with stacktrace, source context and occurrences', function (): void {
    fakeExceptionRecords();

    Livewire::test(TraceDrawer::class)
        ->dispatch('telemetry-ui:open-exception', group: 'abc123def456')
        ->assertSet('exceptionGroup', 'abc123def456')
        ->assertSee('PaymentDeclined')
        ->assertSee('Card declined by issuer')
        ->assertSee('app/Services/Checkout.php:42')
        ->assertSee('#0 app/Services/Checkout.php(42): charge()')      // stacktrace off the Loki record
        ->assertSee('throw new PaymentDeclined();')                    // source context
        ->assertSeeHtml('is-throw-line')                               // throw line highlighted
        ->assertSee('backend')
        // Occurrences link to their traces (stacking onto the drawer).
        ->assertSeeHtml('data-trace-id="1111111111111111aaaaaaaaaaaaaaaa"');
});

it('deep links from ?exception= and filters loki on the fingerprint label', function (): void {
    fakeExceptionRecords();

    Livewire::withQueryParams(['exception' => 'abc123def456'])
        ->test(TraceDrawer::class)
        ->assertSee('PaymentDeclined');

    Http::assertSent(function ($request): bool {
        $url = rawurldecode($request->url());

        return str_contains($url, 'loki.test')
            && str_contains($url, 'exception_group="abc123def456"');
    });
});

it('falls back to browser exception spans for a frontend group', function (): void {
    $group = ExceptionFingerprint::compute('TypeError', 'https://app.test/js/checkout.js', 10);

    Http::fake([
        // No backend records for this group…
        'loki.test:3100/*' => Http::response([
            'status' => 'success',
            'data' => ['resultType' => 'streams', 'result' => []],
        ]),
        // …so the drawer matches browser exception spans by computed fingerprint.
        'tempo.test:3200/api/search*' => Http::response([
            'traces' => [
                ['traceID' => '2222222222222222bbbbbbbbbbbbbbbb', 'rootServiceName' => 'cbox-web', 'rootTraceName' => 'load', 'startTimeUnixNano' => '1735689600000000000', 'durationMs' => 5,
                    'spanSets' => [['spans' => [['spanID' => 'a1', 'name' => 'exception', 'startTimeUnixNano' => '1735689600000000000', 'durationNanos' => '0', 'attributes' => [
                        ['key' => 'exception.type', 'value' => ['stringValue' => 'TypeError']],
                        ['key' => 'exception.message', 'value' => ['stringValue' => 'Cannot read foo of undefined']],
                        ['key' => 'exception.file', 'value' => ['stringValue' => 'https://app.test/js/checkout.js']],
                        ['key' => 'exception.line', 'value' => ['intValue' => '10']],
                        ['key' => 'browser', 'value' => ['boolValue' => true]],
                    ]]]]]],
            ],
        ]),
    ]);

    Livewire::test(TraceDrawer::class)
        ->dispatch('telemetry-ui:open-exception', group: $group)
        ->assertSee('TypeError')
        ->assertSee('Cannot read foo of undefined')
        ->assertSee('frontend')
        ->assertSee('Browser errors carry no stacktrace')
        ->assertSeeHtml('data-trace-id="2222222222222222bbbbbbbbbbbbbbbb"');
});

it('forces the exception lookups inside the tenancy scope lock', function (): void {
    Http::fake([
        'loki.test:3100/*' => Http::response(['status' => 'success', 'data' => ['resultType' => 'streams', 'result' => []]]),
        'tempo.test:3200/*' => Http::response(['traces' => []]),
    ]);

    app(TelemetryUiManager::class)->restrictScopeUsing(fn ($user): array => ['services' => ['checkout']]);

    Livewire::test(TraceDrawer::class)
        ->dispatch('telemetry-ui:open-exception', group: 'abc123def456');

    Http::assertSent(function ($request): bool {
        $url = rawurldecode($request->url());

        return str_contains($url, 'loki.test')
            && str_contains($url, 'service_name="checkout"')
            && str_contains($url, 'exception_group="abc123def456"');
    });

    // The empty Loki result falls back to Tempo — scoped too.
    Http::assertSent(function ($request): bool {
        $url = rawurldecode($request->url());

        return str_contains($url, '/api/search')
            && str_contains($url, 'resource.service.name = "checkout"');
    });
});

it('rejects an error-group id with query metacharacters without querying', function (): void {
    Http::fake();

    Livewire::test(TraceDrawer::class)
        ->dispatch('telemetry-ui:open-exception', group: 'x" } || { span.a = "b')
        ->assertSee('Not a valid error-group id');

    Http::assertNothingSent();
});

it('shows an empty state when the group has no recent occurrences', function (): void {
    Http::fake([
        'loki.test:3100/*' => Http::response(['status' => 'success', 'data' => ['resultType' => 'streams', 'result' => []]]),
        'tempo.test:3200/*' => Http::response(['traces' => []]),
    ]);

    Livewire::test(TraceDrawer::class)
        ->dispatch('telemetry-ui:open-exception', group: 'abc123def456')
        ->assertSee('No occurrences of this error group');
});
