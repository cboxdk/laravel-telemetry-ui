<?php

declare(strict_types=1);

use Cbox\TelemetryUi\Panels\ListsRows;
use Cbox\TelemetryUi\TelemetryUiManager;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * Pages whose signals have no per-event record yet: the companion packages
 * emit only counters for them. Each needs an emitter change upstream, not a
 * panel here. Remove a page from this list when its events arrive.
 */
const PAGES_WITHOUT_EVENTS = [
    'features',        // laravel-telemetry's Pennant instrumentation: counters only
    'statamic-stache', // statamic-telemetry's Stache listeners: counters only
];

it('gives every page rows to open, not only aggregates', function (): void {
    $manager = app(TelemetryUiManager::class);
    $aggregateOnly = [];

    foreach (array_keys($manager->pages()) as $page) {
        $panels = $manager->panels($page);

        if ($panels === [] || in_array($page, PAGES_WITHOUT_EVENTS, true)) {
            continue;
        }

        if (array_filter($panels, static fn (string $panel): bool => is_subclass_of($panel, ListsRows::class)) === []) {
            $aggregateOnly[] = $page;
        }
    }

    expect($aggregateOnly)->toBe([]);
});

it('still lacks rows only where the emitters do', function (): void {
    $manager = app(TelemetryUiManager::class);

    foreach (PAGES_WITHOUT_EVENTS as $page) {
        expect(array_filter($manager->panels($page), static fn (string $panel): bool => is_subclass_of($panel, ListsRows::class)))
            ->toBe([], "{$page} has rows now: take it off PAGES_WITHOUT_EVENTS");
    }
});

/**
 * @param  array<string, string|int>  $attributes
 */
function oneSpanHit(string $name, array $attributes, float $ms = 12.0, string $root = 'GET /orders'): array
{
    $now = time();

    return ['traces' => [[
        'traceID' => 'bbbb2222bbbb2222bbbb2222bbbb2222',
        'rootServiceName' => 'shop',
        'rootTraceName' => $root,
        'startTimeUnixNano' => (string) (($now - 30) * 1_000_000_000),
        'durationMs' => 200,
        'spanSets' => [['spans' => [[
            'spanID' => 's1',
            'name' => $name,
            'startTimeUnixNano' => (string) (($now - 29) * 1_000_000_000),
            'durationNanos' => (string) (int) ($ms * 1_000_000),
            'attributes' => array_map(
                static fn (string $key, string|int $value): array => ['key' => $key, 'value' => is_int($value) ? ['intValue' => (string) $value] : ['stringValue' => $value]],
                array_keys($attributes),
                array_values($attributes),
            ),
        ]]]],
    ]]];
}

function fakeBackends(array $tempo, array $loki = []): void
{
    Http::fake([
        'tempo.test:3200/api/search*' => Http::response($tempo),
        'loki.test:3100/*' => Http::response(['status' => 'success', 'data' => ['resultType' => 'streams', 'result' => $loki]]),
        'prometheus.test:9090/*' => Http::response(['status' => 'success', 'data' => ['resultType' => 'vector', 'result' => []]]),
    ]);
}

it('lists each span of its kind, with the fields that say what it was', function (string $panel, string $traceql, string $span, array $attributes, string $column, string $value, ?string $sub): void {
    fakeBackends(oneSpanHit($span, [...$attributes, 'status' => 'ok']));

    $response = $this->getJson(panelUrl($panel, ['period' => '1h']))
        ->assertOk()
        ->assertJsonPath('error', null)
        ->assertJsonCount(1, 'rows')
        ->assertJsonPath("rows.0.{$column}.v", $value)
        ->assertJsonPath('rows.0.from.v', 'GET /orders')
        ->assertJsonPath('rows.0._link.to', 'trace');

    if ($sub !== null) {
        $response->assertJsonPath("rows.0.{$column}.sub", $sub);
    }

    Http::assertSent(fn (Request $request): bool => str_contains((string) (requestQuery($request)['q'] ?? ''), $traceql));
})->with([
    'job runs' => ['job-run-log', 'span.messaging.operation.type = "process"', 'App\\Jobs\\SendInvoice process', ['laravel.job.class' => 'App\\Jobs\\SendInvoice', 'messaging.destination.name' => 'billing', 'laravel.job.attempts' => 3], 'job', 'SendInvoice', 'billing · attempt 3'],
    'command runs' => ['command-run-log', 'span.laravel.command != nil', 'artisan reports:send', ['laravel.command' => 'reports:send', 'laravel.command.exit_code' => '1'], 'command', 'reports:send', null],
    'task runs' => ['schedule-run-log', 'span.schedule.task != nil', 'schedule reports:send', ['schedule.task' => 'reports:send', 'schedule.cron' => '0 * * * *'], 'task', 'reports:send', '0 * * * *'],
    'storage' => ['storage-log', 'span.storage.operation != nil', 'storage put', ['storage.disk' => 's3', 'storage.operation' => 'put', 'storage.path' => 'invoices/1.pdf'], 'path', 'invoices/1.pdf', 's3'],
    'cache' => ['cache-log', 'span.cache.operation != "hit"', 'cache.miss', ['cache.operation' => 'miss', 'cache.key' => 'user:1', 'cache.store' => 'redis'], 'key', 'user:1', 'redis'],
    'broadcasts' => ['broadcast-log', 'span.broadcasting.event != nil', 'broadcast App\\Events\\OrderShipped', ['broadcasting.event' => 'App\\Events\\OrderShipped', 'broadcasting.driver' => 'reverb', 'broadcasting.channels' => 'private', 'broadcasting.channel.count' => 1], 'event', 'OrderShipped', 'reverb'],
    'form submissions' => ['form-submission-log', 'span.url.path =~ ".*/!/forms/.+"', 'POST /!/forms/{form}', ['url.path' => '/!/forms/contact', 'http.response.status_code' => 422], 'form', 'contact', null],
    'glide images' => ['glide-request-log', 'span.url.path =~ "/img/.+"', 'GET /img/{path}', ['url.path' => '/img/hero.jpg', 'http.response.status_code' => 200], 'image', '/img/hero.jpg', null],
]);

it('marks a non-zero exit as a failed command', function (): void {
    fakeBackends(oneSpanHit('artisan reports:send', ['laravel.command' => 'reports:send', 'laravel.command.exit_code' => '1', 'status' => 'error']));

    $this->getJson(panelUrl('command-run-log', ['period' => '1h']))
        ->assertJsonPath('rows.0.exit.v', '1')
        ->assertJsonPath('rows.0.exit.tone', 'danger')
        ->assertJsonPath('rows.0.outcome.v', 'failed')
        ->assertJsonPath('rows.0.command.link.to', 'entity');
});

it('scopes the queue page\'s runs to its queue', function (): void {
    fakeBackends(['traces' => []]);

    $this->getJson(panelUrl('queue-job-runs', ['period' => '1h', 'queue' => 'billing']))->assertOk();

    Http::assertSent(fn (Request $request): bool => str_contains((string) (requestQuery($request)['q'] ?? ''), 'span.messaging.destination.name = "billing"'));
});

/**
 * A Loki stream holding one telemetry event: the name as the line, the
 * attributes (and the resource every record carries) as labels.
 *
 * @param  array<string, string>  $attributes
 * @return array<string, mixed>
 */
function eventStream(string $event, array $attributes): array
{
    return [
        'stream' => [
            'service_name' => 'shop', 'host_name' => 'web-1', 'telemetry_sdk_name' => 'cboxdk/laravel-telemetry',
            'severity_text' => 'INFO', 'trace_id' => 'cccc3333cccc3333cccc3333cccc3333', ...$attributes,
        ],
        'values' => [[(string) ((time() - 60) * 1_000_000_000), $event]],
    ];
}

it('lists each event of a family with what it said, not the resource it came from', function (string $panel, string $event, array $attributes, string $said): void {
    fakeBackends(['traces' => []], [eventStream($event, $attributes)]);

    $this->getJson(panelUrl($panel, ['period' => '1h']))
        ->assertOk()
        ->assertJsonPath('rows.0.event.v', $event)
        ->assertJsonPath('rows.0.event.sub', 'web-1')
        ->assertJsonPath('rows.0.details.v', $said)
        ->assertJsonPath('rows.0._link.to', 'trace');
})->with([
    'autoscaler' => ['autoscale-event-log', 'queue_autoscale.scaling.action', ['queue' => 'default', 'workers_from' => '2', 'workers_to' => '5'], 'queue default · workers.from 2 · workers.to 5'],
    'horizon' => ['horizon-event-log', 'horizon.process.out_of_memory', ['type' => 'supervisor'], 'type supervisor'],
    'statamic purges' => ['cache-purge-log', 'statamic.cache.purge', ['statamic_cache_url' => '/blog'], 'statamic.cache.url /blog'],
]);

it('reads an incident as one', function (): void {
    fakeBackends(['traces' => []], [eventStream('queue_autoscale.sla.breached', ['queue' => 'emails'])]);

    $this->getJson(panelUrl('autoscale-event-log', ['period' => '1h']))->assertJsonPath('rows.0.event.tone', 'danger');
});

it('lists each exception as it happened, linked to its trace and its group', function (): void {
    fakeBackends(['traces' => []], [[
        'stream' => ['service_name' => 'shop', 'exception_group' => 'abc123', 'exception_type' => 'App\\Exceptions\\PaymentFailed', 'exception_message' => 'Card declined', 'trace_id' => 'dddd4444dddd4444dddd4444dddd4444', 'user_id' => '25'],
        'values' => [[(string) ((time() - 60) * 1_000_000_000), 'Card declined']],
    ]]);

    $this->getJson(panelUrl('exception-log', ['period' => '1h']))
        ->assertOk()
        ->assertJsonPath('rows.0.exception.v', 'PaymentFailed')
        ->assertJsonPath('rows.0.exception.sub', 'Card declined')
        ->assertJsonPath('rows.0.exception.link.to', 'error')
        ->assertJsonPath('rows.0.user.v', '#25')
        ->assertJsonPath('rows.0._link.to', 'trace');
});

it('lists each page view with where the visitor came from', function (): void {
    fakeBackends(['traces' => []], [[
        'stream' => ['service_name' => 'shop', 'url_path' => '/pricing', 'http_request_header_referer' => 'https://news.ycombinator.com/item', 'analytics_utm_campaign' => 'launch', 'geo_country_iso_code' => 'DK', 'device_type' => 'mobile'],
        'values' => [[(string) ((time() - 60) * 1_000_000_000), 'analytics.page_view']],
    ]]);

    $this->getJson(panelUrl('page-view-log', ['period' => '1h']))
        ->assertOk()
        ->assertJsonPath('rows.0.page.v', '/pricing')
        ->assertJsonPath('rows.0.from.v', 'news.ycombinator.com')
        ->assertJsonPath('rows.0.from.sub', 'launch')
        ->assertJsonPath('rows.0.visitor.v', 'DK · mobile');
});

it('shows one host\'s log lines on its page, without a live tail that would not stay on it', function (): void {
    fakeBackends(['traces' => []]);

    $this->getJson(panelUrl('host-logs', ['period' => '1h', 'host' => 'web-1']))
        ->assertOk()
        ->assertJsonPath('kind', 'logs')
        ->assertJsonPath('title', 'Logs from this host')
        ->assertJsonPath('stream', null);

    Http::assertSent(fn (Request $request): bool => str_contains(urldecode($request->url()), 'host_name="web-1"'));
});
