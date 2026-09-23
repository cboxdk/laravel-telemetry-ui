<?php

declare(strict_types=1);

use Cbox\TelemetryUi\Panels\Ui;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;

/** One span, one service — a trace Tempo can answer with. */
function tempoTraceBody(): array
{
    return ['batches' => [[
        'resource' => ['attributes' => [['key' => 'service.name', 'value' => ['stringValue' => 'shop']]]],
        'scopeSpans' => [['spans' => [
            ['spanId' => 'a1', 'name' => 'GET /orders', 'kind' => 'SPAN_KIND_SERVER', 'startTimeUnixNano' => '1735689600000000000', 'endTimeUnixNano' => '1735689601000000000'],
        ]]],
    ]]];
}

/** @return list<string> the Tempo trace-by-id URLs sent, decoded */
function tempoTraceLookups(): array
{
    return collect(Http::recorded())
        ->map(fn (array $pair): string => rawurldecode((string) $pair[0]->url()))
        ->filter(fn (string $url): bool => str_contains($url, 'tempo.test:3200/api/traces/'))
        ->values()
        ->all();
}

beforeEach(fn () => Gate::define('viewTelemetryUi', fn (?object $user = null, ?string $page = null): bool => true));

it('asks the trace store about the hour around a trace whose start is known', function (): void {
    Http::fake([
        'tempo.test:3200/api/traces/*' => Http::response(tempoTraceBody()),
        '*' => Http::response(lokiStreams([])),
    ]);

    $this->getJson(apiUrl('traces/abc123abc123abc123abc123abc123ab', ['at' => '1735689600500']))->assertOk();

    expect(tempoTraceLookups())->toBe([
        'http://tempo.test:3200/api/traces/abc123abc123abc123abc123abc123ab?start=1735686000&end=1735693200',
    ]);
});

it('falls back to a full lookup when the window misses the trace', function (): void {
    Http::fake([
        'tempo.test:3200/api/traces/*' => function ($request) {
            return str_contains($request->url(), 'start=')
                ? Http::response('trace not found', 404)
                : Http::response(tempoTraceBody());
        },
        '*' => Http::response(lokiStreams([])),
    ]);

    $this->getJson(apiUrl('traces/abc123abc123abc123abc123abc123ab', ['at' => '1600000000000']))
        ->assertOk()
        ->assertJsonPath('root.name', 'GET /orders');

    expect(tempoTraceLookups())->toHaveCount(2)
        ->and(tempoTraceLookups()[1])->toBe('http://tempo.test:3200/api/traces/abc123abc123abc123abc123abc123ab');
});

it('looks a trace up in full when no usable start is given', function (?string $at): void {
    Http::fake([
        'tempo.test:3200/api/traces/*' => Http::response(tempoTraceBody()),
        '*' => Http::response(lokiStreams([])),
    ]);

    $this->getJson(apiUrl('traces/abc123abc123abc123abc123abc123ab', $at === null ? [] : ['at' => $at]))->assertOk();

    expect(tempoTraceLookups())->toBe(['http://tempo.test:3200/api/traces/abc123abc123abc123abc123abc123ab']);
})->with([
    'none' => [null],
    'not a number' => ['yesterday'],
    'negative' => ['-5'],
    'absurdly large' => ['99999999999999999999'],
]);

it('puts the start on the trace links it builds', function (): void {
    expect(Ui::trace('abc', new DateTimeImmutable('@1735689600')))
        ->toBe(['to' => 'trace', 'id' => 'abc', 'at' => 1735689600000])
        ->and(Ui::trace('abc'))->toBe(['to' => 'trace', 'id' => 'abc']);
});
