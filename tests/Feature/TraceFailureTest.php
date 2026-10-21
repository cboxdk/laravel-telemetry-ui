<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;

/*
 * Why a span failed, on the span: the exception the log store recorded
 * against it (by its span id), and what the span carries itself — OTel
 * records an exception as a span event and the reason as the status
 * description, neither of which is an attribute.
 */

const FAILURE_TRACE = 'abc123abc123abc123abc123abc123ab';

/**
 * A request that queued a job; the job's first two attempts threw. Tempo's
 * trace API answers span ids in base64, as the real one does.
 *
 * @param  list<array<string, mixed>>  $records  Loki streams for the trace
 */
function failingTrace(array $records = []): void
{
    $span = fn (string $id, ?string $parent, string $name, int $at, array $extra = []): array => [
        'spanId' => $id, 'parentSpanId' => $parent, 'name' => $name, 'kind' => 'SPAN_KIND_CONSUMER',
        'startTimeUnixNano' => (string) (1735689600000000000 + $at * 1_000_000), 'endTimeUnixNano' => (string) (1735689600000000000 + ($at + 30) * 1_000_000),
        'attributes' => [['key' => 'laravel.job.class', 'value' => ['stringValue' => 'App\\Jobs\\ChargeCard']]],
        ...$extra,
    ];

    Http::fake([
        'tempo.test:3200/api/traces/*' => Http::response(['batches' => [[
            'resource' => ['attributes' => [['key' => 'service.name', 'value' => ['stringValue' => 'shop']]]],
            'scopeSpans' => [['spans' => [
                [...$span('EREiIjMzREQ=', null, 'POST /checkout', 0), 'kind' => 'SPAN_KIND_SERVER'],
                // Attempt 1: the emitter recorded the exception as a span event.
                $span('t61rcWkgMzE=', 'EREiIjMzREQ=', 'App\\Jobs\\ChargeCard process', 100, [
                    'status' => ['code' => 'STATUS_CODE_ERROR', 'message' => 'Card declined'],
                    'events' => [['name' => 'exception', 'timeUnixNano' => '1735689600120000000', 'attributes' => [
                        ['key' => 'exception.type', 'value' => ['stringValue' => 'App\\Payments\\CardDeclined']],
                        ['key' => 'exception.message', 'value' => ['stringValue' => 'Card declined']],
                        ['key' => 'exception.stacktrace', 'value' => ['stringValue' => "#0 app/Payments/Charge.php(42)\n#1 {main}"]],
                    ]]],
                ]),
                // Attempt 2: only a status description.
                $span('APBnqgupArc=', 'EREiIjMzREQ=', 'App\\Jobs\\ChargeCard process', 300, [
                    'status' => ['code' => 'STATUS_CODE_ERROR', 'message' => 'released for retry'],
                ]),
            ]]],
        ]]]),
        'prometheus.test:9090/*' => Http::response(['status' => 'success', 'data' => ['resultType' => 'matrix', 'result' => []]]),
        'loki.test:3100/*' => Http::response(['status' => 'success', 'data' => ['resultType' => 'streams', 'result' => $records]]),
    ]);
}

/** @return array<string, mixed> */
function declined(string $spanHex, string $at): array
{
    return [
        'stream' => ['service_name' => 'shop', 'trace_id' => FAILURE_TRACE, 'span_id' => $spanHex, 'exception_group' => '0f3a9c2b1d4e', 'exception_type' => 'App\\Payments\\CardDeclined', 'exception_message' => 'Card declined', 'exception_stacktrace' => '#0 app/Payments/Charge.php(42)'],
        'values' => [[$at, 'exception']],
    ];
}

it('names the spans that threw each exception, in the trace\'s own span id form', function (): void {
    failingTrace([declined('b7ad6b7169203331', '1735689600120000000'), declined('00f067aa0ba902b7', '1735689600320000000')]);

    $this->getJson(apiUrl('traces/'.FAILURE_TRACE))
        ->assertOk()
        ->assertJsonCount(1, 'exceptions')
        ->assertJsonPath('exceptions.0.spans', ['t61rcWkgMzE=', 'APBnqgupArc='])
        ->assertJsonPath('exceptions.0.stacktrace', '#0 app/Payments/Charge.php(42)');
});

it('leaves an exception\'s spans empty when the record carries no usable span id', function (): void {
    failingTrace([declined('', '1735689600120000000'), declined('not-a-span-id', '1735689600320000000')]);

    $this->getJson(apiUrl('traces/'.FAILURE_TRACE))
        ->assertOk()
        ->assertJsonPath('exceptions.0.spans', []);
});

it('folds a span\'s exception event and status description into its attributes', function (): void {
    failingTrace();

    $rows = collect($this->getJson(apiUrl('traces/'.FAILURE_TRACE))->assertOk()->json('waterfall'))->keyBy('span.spanId');

    expect($rows['t61rcWkgMzE=']['span']['attributes'])
        ->toMatchArray([
            'exception.type' => 'App\\Payments\\CardDeclined',
            'exception.message' => 'Card declined',
            'exception.stacktrace' => "#0 app/Payments/Charge.php(42)\n#1 {main}",
            'otel.status_description' => 'Card declined',
        ])
        ->and($rows['APBnqgupArc=']['span']['attributes'])
        ->toHaveKey('otel.status_description', 'released for retry')
        ->not->toHaveKey('exception.type')
        ->and($rows['EREiIjMzREQ=']['span']['attributes'])
        ->not->toHaveKey('otel.status_description');
});
