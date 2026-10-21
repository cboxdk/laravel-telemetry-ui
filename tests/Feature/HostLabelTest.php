<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;

/*
 * The machine a card is about, named the way the backends name it
 * (`telemetry-ui.scope.labels.*.host`). A Prometheus scrape that stamps
 * `hostname` carries no `host_name`: a host card that matched `host_name`
 * read every machine's series, a fleet chart that averaged by it folded every
 * machine into one, and a host's log panel found no lines.
 */

beforeEach(function (): void {
    Gate::define('viewTelemetryUi', fn (?object $user = null, ?string $page = null): bool => true);

    Http::fake(fn (Request $request) => str_contains($request->url(), 'loki.test')
        ? Http::response(lokiStreams([]))
        : Http::response(['status' => 'success', 'data' => ['resultType' => 'matrix', 'result' => []]]));
});

/** @return list<string> */
function sentPromql(): array
{
    $out = [];

    foreach (Http::recorded() as [$request]) {
        if (str_contains($request->url(), 'prometheus.test') && is_string(requestQuery($request)['query'] ?? null)) {
            $out[] = (string) requestQuery($request)['query'];
        }
    }

    return $out;
}

function hostLabelled(string $label): void
{
    config()->set('telemetry-ui.scope.labels.metrics.host', $label);
    config()->set('telemetry-ui.scope.labels.logs.host', $label);
}

it('scopes a host card to the configured host label', function (string $panel): void {
    hostLabelled('hostname');

    $this->getJson(panelUrl($panel, ['host' => 'web-1']))->assertOk();

    expect(sentPromql())->not->toBeEmpty()
        ->each(fn ($query) => $query->toContain('hostname=~"web-1|"')->not->toContain('host_name'));
})->with(['host-detail-memory', 'host-detail-filesystem', 'host-detail-cpu']);

it('averages each machine by the configured host label before summing the fleet', function (string $panel): void {
    hostLabelled('hostname');

    $this->getJson(panelUrl($panel))->assertOk();

    expect(sentPromql())->not->toBeEmpty()
        ->each(fn ($query) => $query->toContain('avg by (hostname, ')->not->toContain('host_name'));
})->with(['system-memory', 'system-filesystem']);

it('reads a host\'s log lines by the configured host label', function (): void {
    hostLabelled('hostname');

    $this->getJson(panelUrl('host-logs', ['host' => 'web-1']))->assertOk();

    expect(sentLogql())->not->toBeEmpty()->each(fn ($query) => $query->toContain('hostname="web-1"')->not->toContain('host_name'));
});

it('keeps host_name when nothing is configured', function (): void {
    $this->getJson(panelUrl('host-detail-memory', ['host' => 'web-1']))->assertOk();
    $this->getJson(panelUrl('system-memory'))->assertOk();
    $this->getJson(panelUrl('host-logs', ['host' => 'web-1']))->assertOk();

    expect(sentPromql())->each->toContain('host_name')
        ->and(sentLogql())->each->toContain('host_name="web-1"');
});
