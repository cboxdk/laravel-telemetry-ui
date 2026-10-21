<?php

declare(strict_types=1);

use Cbox\TelemetryUi\Connectors\ConnectionManager;
use Cbox\TelemetryUi\Queries\Ir\TraceCondition;
use Cbox\TelemetryUi\Queries\Ir\TraceQuery;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * A Tempo search answer: one trace (the request that sent things) holding the
 * given spans, each `[name, offset seconds, duration ms, attributes]`.
 *
 * @param  list<array{0: string, 1: int, 2: float, 3: array<string, string|int>}>  $spans
 * @return array<string, mixed>
 */
function tempoHits(array $spans, string $root = 'POST /checkout'): array
{
    $now = time();

    return ['traces' => [[
        'traceID' => 'aaaa1111aaaa1111aaaa1111aaaa1111',
        'rootServiceName' => 'shop',
        'rootTraceName' => $root,
        'startTimeUnixNano' => (string) (($now - 60) * 1_000_000_000),
        'durationMs' => 400,
        'spanSets' => [['spans' => array_map(static fn (array $s, int $i): array => [
            'spanID' => 'span'.$i,
            'name' => $s[0],
            'startTimeUnixNano' => (string) (($now - 60 + $s[1]) * 1_000_000_000),
            'durationNanos' => (string) (int) ($s[2] * 1_000_000),
            'attributes' => array_map(
                static fn (string $key, string|int $value): array => ['key' => $key, 'value' => is_int($value) ? ['intValue' => (string) $value] : ['stringValue' => $value]],
                array_keys($s[3]),
                array_values($s[3]),
            ),
        ], $spans, array_keys($spans))]],
    ]]];
}

function fakeTempo(array $answer): void
{
    Http::fake([
        'tempo.test:3200/api/search*' => Http::response($answer),
        'prometheus.test:9090/*' => Http::response(['status' => 'success', 'data' => ['resultType' => 'vector', 'result' => []]]),
        'loki.test:3100/*' => Http::response(['status' => 'success', 'data' => ['resultType' => 'streams', 'result' => []]]),
    ]);
}

it('lists each message sent, newest first, with what sent it', function (): void {
    fakeTempo(tempoHits([
        ['mail.send', 1, 4.0, ['mail.subject' => 'Your receipt', 'mail.recipients' => 1, 'status' => 'ok']],
        ['mail.send', 2, 1500.0, ['mail.subject' => 'Welcome', 'mail.recipients' => 3, 'status' => 'ok']],
    ]));

    $this->getJson(panelUrl('mail-log', ['period' => '1h']))
        ->assertOk()
        ->assertJsonPath('title', 'Mail sent')
        ->assertJsonCount(2, 'rows')
        ->assertJsonPath('rows.0.subject.v', 'Welcome')
        ->assertJsonPath('rows.0.subject.sub', '3 recipients')
        ->assertJsonPath('rows.0.from.v', 'POST /checkout')
        ->assertJsonPath('rows.0.outcome.v', 'ok')
        // Over a second on the transport reads as slow.
        ->assertJsonPath('rows.0.duration.tone', 'warn')
        ->assertJsonPath('rows.0._link.to', 'trace')
        ->assertJsonPath('rows.1.subject.v', 'Your receipt')
        ->assertJsonPath('rows.1.subject.sub', '1 recipient');

    Http::assertSent(function (Request $request): bool {
        $q = requestQuery($request);

        return str_contains($request->url(), '/api/search')
            && str_contains((string) ($q['q'] ?? ''), 'name = "mail.send"')
            && str_contains((string) ($q['q'] ?? ''), 'select(status, statusMessage, span.error.type, span.mail.subject, span.mail.recipients)')
            // Every send in a trace, not Tempo's default three.
            && ($q['spss'] ?? null) === '100';
    });
});

it('shows each notification delivery per channel, and which ones failed', function (): void {
    fakeTempo(tempoHits([
        ['notification.send', 1, 50.0, ['notification.class' => 'App\\Notifications\\OrderShipped', 'notification.channel' => 'mail', 'status' => 'ok']],
        ['notification.send', 2, 3.0, ['notification.class' => 'App\\Notifications\\OrderShipped', 'notification.channel' => 'database', 'status' => 'error']],
    ]));

    $this->getJson(panelUrl('notification-log', ['period' => '1h']))
        ->assertOk()
        ->assertJsonPath('rows.0.notification.v', 'OrderShipped')
        ->assertJsonPath('rows.0.notification.sub', 'App\\Notifications\\OrderShipped')
        ->assertJsonPath('rows.0.channel.v', 'database')
        ->assertJsonPath('rows.0.outcome.v', 'failed')
        ->assertJsonPath('rows.0.outcome.tone', 'danger')
        ->assertJsonPath('rows.1.channel.v', 'mail')
        ->assertJsonPath('rows.1.outcome.v', 'ok');
});

it('narrows to failures in the search itself, and sorts by the slowest', function (): void {
    fakeTempo(tempoHits([
        ['notification.send', 1, 50.0, ['notification.class' => 'A', 'notification.channel' => 'mail', 'status' => 'error']],
        ['notification.send', 2, 3.0, ['notification.class' => 'B', 'notification.channel' => 'mail', 'status' => 'error']],
    ]));

    $this->getJson(panelUrl('notification-log', ['period' => '1h', 'notif_status' => 'error', 'notif_sort' => 'slow']))
        ->assertOk()
        ->assertJsonPath('rows.0.notification.v', 'A')
        ->assertJsonPath('controls.0.value', 'error')
        ->assertJsonPath('controls.1.value', 'slow');

    Http::assertSent(fn (Request $request): bool => str_contains((string) (requestQuery($request)['q'] ?? ''), 'status = error'));
});

it('says when there is nothing to show', function (): void {
    fakeTempo(['traces' => []]);

    $this->getJson(panelUrl('mail-log', ['period' => '1h']))
        ->assertOk()
        ->assertJsonCount(0, 'rows')
        ->assertJsonPath('empty', 'No mail sent in this period.');
});

it('says so when the trace store fails, instead of an empty log', function (): void {
    Http::fake(['tempo.test:3200/*' => Http::response('boom', 500), '*' => Http::response(['status' => 'success', 'data' => ['resultType' => 'vector', 'result' => []]])]);

    $this->getJson(panelUrl('mail-log', ['period' => '1h']))
        ->assertOk()
        ->assertJsonCount(0, 'rows')
        ->assertJsonPath('error', fn (mixed $e): bool => is_string($e) && $e !== '');
});

it('puts the row-level logs on the mail page', function (): void {
    $this->getJson(apiUrl('pages/mail'))
        ->assertOk()
        ->assertJsonPath('panels.2.id', 'mail-log')
        ->assertJsonPath('panels.3.id', 'notification-log');
});

it('asks Tempo for every matching span only when a search reads them', function (): void {
    fakeTempo(['traces' => []]);
    $traces = app(ConnectionManager::class)->traces();

    $traces->search(new TraceQuery([TraceCondition::eq('name', 'mail.send')]), new DateTimeImmutable('-1 hour'), new DateTimeImmutable);
    $traces->search((new TraceQuery([TraceCondition::eq('name', 'mail.send')]))->select('span.mail.subject'), new DateTimeImmutable('-1 hour'), new DateTimeImmutable);

    $spss = array_map(static fn (array $pair): mixed => requestQuery($pair[0])['spss'] ?? null, Http::recorded()->all());

    expect($spss)->toBe([null, '100']);
});

it('says why a send failed, from the exception the emitter recorded', function (): void {
    fakeTempo(tempoHits([
        ['mail.send', 1, 30.0, ['mail.subject' => 'Receipt', 'mail.recipients' => 1, 'status' => 'error', 'error.type' => 'Symfony\\Component\\Mailer\\Exception\\TransportException', 'statusMessage' => '554 5.7.1 Relay access denied']],
    ]));

    $this->getJson(panelUrl('mail-log', ['period' => '1h']))
        ->assertOk()
        ->assertJsonPath('rows.0.outcome.v', 'failed')
        ->assertJsonPath('rows.0.outcome.sub', 'TransportException: 554 5.7.1 Relay access denied');

    Http::assertSent(fn (Request $request): bool => str_contains((string) (requestQuery($request)['q'] ?? ''), 'select(status, statusMessage, span.error.type,'));
});
