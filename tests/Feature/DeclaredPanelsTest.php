<?php

declare(strict_types=1);

use Cbox\TelemetryUi\Facades\TelemetryUi;
use Illuminate\Support\Facades\Http;

/** A Go sidecar exporting OTLP: a page of charts, declared, no panel classes. */
beforeEach(function (): void {
    TelemetryUi::page('indexer', 'Indexer', group: 'Infrastructure');
    TelemetryUi::setPanels('indexer', []);

    TelemetryUi::metricPanel('indexer-queue', page: 'indexer', title: 'Queue depth', metric: 'indexer_queue_depth', type: 'area', stat: 'Now', where: ['shard' => 'a']);
    TelemetryUi::metricPanel('indexer-docs', page: 'indexer', title: 'Documents indexed', metric: 'indexer_docs_total', rate: true, by: 'status', span: 2);
    TelemetryUi::metricPanel('indexer-latency', page: 'indexer', title: 'Latency p95', metric: 'indexer_duration_seconds_bucket', quantile: 0.95, unit: 'ms');

    Http::fake([
        'prometheus.test:9090/api/v1/query_range*' => Http::response(['status' => 'success', 'data' => ['resultType' => 'matrix', 'result' => [
            ['metric' => ['status' => 'ok'], 'values' => [[1735689600, '12'], [1735689660, '15']]],
        ]]]),
        'prometheus.test:9090/api/v1/query*' => Http::response(['status' => 'success', 'data' => ['resultType' => 'vector', 'result' => [
            ['metric' => [], 'value' => [1735689600, '7']],
        ]]]),
    ]);
});

it('lists declared panels on their page, with their spans', function (): void {
    $this->getJson(apiUrl('pages/indexer'))
        ->assertOk()
        ->assertJsonPath('panels', [
            ['id' => 'indexer-queue', 'span' => 1],
            ['id' => 'indexer-docs', 'span' => 2],
            ['id' => 'indexer-latency', 'span' => 1],
        ]);
});

it('serves a declared gauge as a chart with a headline value', function (): void {
    $this->getJson(panelUrl('indexer-queue'))
        ->assertOk()
        ->assertJsonPath('kind', 'chart')
        ->assertJsonPath('title', 'Queue depth')
        ->assertJsonPath('type', 'area')
        ->assertJsonPath('stats.0.label', 'Now');

    Http::assertSent(function ($request): bool {
        $q = rawurldecode(requestQuery($request)['query'] ?? '');

        return ! str_contains($q, 'indexer_queue_depth') || str_contains($q, 'shard="a"');
    });
});

it('reads a counter as per-minute throughput, split by a label', function (): void {
    $this->getJson(panelUrl('indexer-docs'))->assertOk()->assertJsonPath('series.0.name', 'ok');

    expect(collect(Http::recorded())->map(fn ($pair): string => rawurldecode(requestQuery($pair[0])['query'] ?? ''))
        ->contains(fn (string $q): bool => str_contains($q, 'rate(indexer_docs_total') && str_contains($q, 'by (status)') && str_contains($q, '* 60')))->toBeTrue();
});

it('reads a histogram as a quantile', function (): void {
    $this->getJson(panelUrl('indexer-latency'))->assertOk();

    expect(collect(Http::recorded())->map(fn ($pair): string => rawurldecode(requestQuery($pair[0])['query'] ?? ''))
        ->contains(fn (string $q): bool => str_contains($q, 'histogram_quantile(0.95')))->toBeTrue();
});

it('404s an unknown panel id, and keeps the page gate', function (): void {
    $this->getJson(panelUrl('indexer-nope'))->assertNotFound();
});
