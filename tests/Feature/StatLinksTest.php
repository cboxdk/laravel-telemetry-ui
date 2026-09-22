<?php

declare(strict_types=1);

use Cbox\TelemetryUi\Panels\Ui;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Http::fake([
        'prometheus.test:9090/api/v1/query_range*' => Http::response([
            'status' => 'success',
            'data' => ['resultType' => 'matrix', 'result' => [
                ['metric' => [], 'values' => [[1735689600, '120'], [1735689660, '150']]],
            ]],
        ]),
        'prometheus.test:9090/api/v1/query*' => Http::response([
            'status' => 'success',
            'data' => ['resultType' => 'vector', 'result' => [
                ['metric' => [], 'value' => [1735689600, '4200']],
            ]],
        ]),
    ]);
});

it('fills stat links a panel declares, keeping links the payload already set', function (): void {
    $this->getJson(panelUrl('query-throughput'))
        ->assertOk()
        ->assertJsonPath('stats.0.link', ['to' => 'entities', 'type' => 'query'])
        ->assertJsonPath('stats.1.link', ['to' => 'entities', 'type' => 'query'])
        ->assertJsonPath('stats.3.link', ['to' => 'explore', 'signal' => 'requests', 'where' => ['db.query.duplicate.count>0']]);
});

it('links a job outcome tile to the matching spans', function (): void {
    $this->getJson(panelUrl('jobs-overview'))
        ->assertOk()
        ->assertJsonPath('stats.2.link.where', ['laravel.job.class!=', 'status=error']);
});

it('turns a root operation into a route page or a span-name search', function (): void {
    expect(Ui::rootOperation('GET /users/{id}'))->toBe(['to' => 'entity', 'type' => 'route', 'value' => '/users/{id}'])
        ->and(Ui::rootOperation('App\Jobs\Sync process'))->toBe(['to' => 'explore', 'signal' => 'traces', 'where' => [], 'params' => ['q' => 'App\Jobs\Sync process']]);
});
