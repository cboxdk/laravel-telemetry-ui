<?php

declare(strict_types=1);

use Cbox\TelemetryUi\Panels\Builtin\AutoscaleActions;
use Cbox\TelemetryUi\Panels\Builtin\AutoscaleCluster;
use Cbox\TelemetryUi\Panels\Builtin\AutoscaleSla;
use Cbox\TelemetryUi\Panels\Builtin\AutoscaleWorkers;
use Cbox\TelemetryUi\Panels\Builtin\QueueBacklog;
use Cbox\TelemetryUi\Panels\Builtin\QueueOldestJob;
use Cbox\TelemetryUi\Panels\Builtin\QueuesTable;
use Cbox\TelemetryUi\Panels\Builtin\QueueThroughput;
use Cbox\TelemetryUi\Panels\Builtin\QueueWorkers;
use Cbox\TelemetryUi\TelemetryUiManager;
use Illuminate\Support\Facades\Http;

/** A Prometheus instant-vector response. */
function queuesVector(array $results): array
{
    return ['status' => 'success', 'data' => ['resultType' => 'vector', 'result' => $results]];
}

/** A Prometheus range-matrix response. */
function queuesMatrix(array $results): array
{
    return ['status' => 'success', 'data' => ['resultType' => 'matrix', 'result' => $results]];
}

it('registers the queues and autoscale pages with their metric-family detection', function (): void {
    $pages = app(TelemetryUiManager::class)->pages();

    expect($pages['queues']['detect'])->toBe('queue_metrics_.*')
        ->and($pages['queues']['group'])->toBe('Queues')
        ->and($pages['autoscale']['detect'])->toBe('queue_autoscale_.*')
        ->and($pages['autoscale']['group'])->toBe('Queues');

    expect(app(TelemetryUiManager::class)->panels('queues'))->toContain(QueueBacklog::class, QueuesTable::class)
        ->and(app(TelemetryUiManager::class)->panels('autoscale'))->toContain(AutoscaleWorkers::class, AutoscaleCluster::class);
});

it('charts the backlog by job state', function (): void {
    Http::fake([
        'prometheus.test:9090/api/v1/query_range*' => Http::response(queuesMatrix([
            ['metric' => ['state' => 'pending'], 'values' => [[1735689600, '12'], [1735689660, '18']]],
            ['metric' => ['state' => 'reserved'], 'values' => [[1735689600, '3'], [1735689660, '2']]],
        ])),
        'prometheus.test:9090/api/v1/query?*' => Http::response(queuesVector([
            ['metric' => ['state' => 'pending'], 'value' => [1735689600, '18']],
            ['metric' => ['state' => 'scheduled'], 'value' => [1735689600, '4']],
        ])),
    ]);

    $this->getJson(panelUrl(QueueBacklog::id()))
        ->assertOk()
        ->assertSee('Backlog')
        ->assertSee('Pending')
        ->assertSee('Scheduled')
        ->assertSee('18')
        ->assertSee('4');

    Http::assertSent(function ($request): bool {
        $q = rawurldecode(requestQuery($request)['query'] ?? '');

        return str_contains($q, 'queue_metrics_queue_depth');
    });
});

it('charts per-queue throughput', function (): void {
    Http::fake([
        'prometheus.test:9090/api/v1/query_range*' => Http::response(queuesMatrix([
            ['metric' => ['queue' => 'default'], 'values' => [[1735689600, '40'], [1735689660, '55']]],
        ])),
        'prometheus.test:9090/api/v1/query?*' => Http::response(queuesVector([
            ['metric' => ['queue' => 'default'], 'value' => [1735689600, '55']],
        ])),
    ]);

    $this->getJson(panelUrl(QueueThroughput::id()))
        ->assertOk()
        ->assertSee('Throughput')
        ->assertSee('55');

    Http::assertSent(function ($request): bool {
        $q = rawurldecode(requestQuery($request)['query'] ?? '');

        return str_contains($q, 'queue_metrics_queue_throughput_per_min');
    });
});

it('shows the oldest pending job age', function (): void {
    Http::fake([
        'prometheus.test:9090/api/v1/query_range*' => Http::response(queuesMatrix([
            ['metric' => ['queue' => 'default'], 'values' => [[1735689600, '30'], [1735689660, '95']]],
        ])),
        'prometheus.test:9090/api/v1/query?*' => Http::response(queuesVector([
            ['metric' => ['queue' => 'default'], 'value' => [1735689600, '95']],
        ])),
    ]);

    $this->getJson(panelUrl(QueueOldestJob::id()))
        ->assertOk()
        ->assertSee('Oldest job')
        ->assertSee('1.58min'); // 95s formatted

    Http::assertSent(function ($request): bool {
        $q = rawurldecode(requestQuery($request)['query'] ?? '');

        return str_contains($q, 'queue_metrics_queue_oldest_job_age_seconds');
    });
});

it('charts the worker fleet with utilization', function (): void {
    Http::fake([
        'prometheus.test:9090/api/v1/query_range*' => Http::response(queuesMatrix([
            ['metric' => ['state' => 'busy'], 'values' => [[1735689600, '6'], [1735689660, '8']]],
            ['metric' => ['state' => 'idle'], 'values' => [[1735689600, '2'], [1735689660, '0']]],
        ])),
        'prometheus.test:9090/api/v1/query?*' => function ($request) {
            $q = rawurldecode(requestQuery($request)['query'] ?? '');

            if (str_contains($q, 'utilization')) {
                return Http::response(queuesVector([
                    ['metric' => [], 'value' => [1735689600, '75']],
                ]));
            }

            return Http::response(queuesVector([
                ['metric' => ['state' => 'busy'], 'value' => [1735689600, '8']],
                ['metric' => ['state' => 'idle'], 'value' => [1735689600, '2']],
            ]));
        },
    ]);

    $this->getJson(panelUrl(QueueWorkers::id()))
        ->assertOk()
        ->assertSee('Workers')
        ->assertSee('Busy')
        ->assertSee('Utilization')
        ->assertSee('75%');

    Http::assertSent(function ($request): bool {
        $q = rawurldecode(requestQuery($request)['query'] ?? '');

        return str_contains($q, 'queue_metrics_workers_utilization_percent') && str_contains($q, 'window="current"');
    });
});

it('lists queues with backlog, drain rate and workers', function (): void {
    Http::fake([
        'prometheus.test:9090/api/v1/query_range*' => Http::response(queuesMatrix([
            ['metric' => ['connection' => 'redis', 'queue' => 'default'], 'values' => [[1735689600, '10'], [1735689660, '14']]],
        ])),
        'prometheus.test:9090/api/v1/query?*' => Http::response(queuesVector([
            ['metric' => ['connection' => 'redis', 'queue' => 'default'], 'value' => [1735689600, '14']],
        ])),
    ]);

    $this->getJson(panelUrl(QueuesTable::id()))
        ->assertOk()
        ->assertSee('Queues')
        ->assertSee('default')
        ->assertSee('redis')
        // Rows drill into the queue entity page.
        ->assertJsonPath('rows.0._link', ['to' => 'entity', 'type' => 'queue', 'value' => 'default'])
        ->assertJsonPath('rows.0.connection.badge', 'redis');
});

it('charts autoscaler target against active workers', function (): void {
    Http::fake([
        'prometheus.test:9090/api/v1/query_range*' => Http::response(queuesMatrix([
            ['metric' => [], 'values' => [[1735689600, '4'], [1735689660, '6']]],
        ])),
        'prometheus.test:9090/api/v1/query?*' => Http::response(queuesVector([
            ['metric' => [], 'value' => [1735689600, '6']],
        ])),
    ]);

    $this->getJson(panelUrl(AutoscaleWorkers::id()))
        ->assertOk()
        ->assertSee('Target')
        ->assertSee('Active')
        ->assertSee('6');

    Http::assertSent(function ($request): bool {
        $q = rawurldecode(requestQuery($request)['query'] ?? '');

        return str_contains($q, 'queue_autoscale_workers_target');
    });

    Http::assertSent(function ($request): bool {
        $q = rawurldecode(requestQuery($request)['query'] ?? '');

        return str_contains($q, 'queue_metrics_queue_active_workers');
    });
});

it('charts executed scaling actions by direction', function (): void {
    // The live direction label values are 'up' | 'down' (WorkersScaled action).
    Http::fake([
        'prometheus.test:9090/api/v1/query_range*' => Http::response(queuesMatrix([
            ['metric' => ['direction' => 'up'], 'values' => [[1735689600, '1'], [1735689660, '2']]],
            ['metric' => ['direction' => 'down'], 'values' => [[1735689600, '0'], [1735689660, '1']]],
        ])),
        'prometheus.test:9090/api/v1/query?*' => Http::response(queuesVector([
            ['metric' => ['direction' => 'up'], 'value' => [1735689600, '3']],
            ['metric' => ['direction' => 'down'], 'value' => [1735689600, '1']],
        ])),
    ]);

    $this->getJson(panelUrl(AutoscaleActions::id()))
        ->assertOk()
        ->assertSee('Scaling actions')
        ->assertSee('Scale up')
        ->assertSee('Scale down')
        ->assertSee('3');

    Http::assertSent(function ($request): bool {
        $q = rawurldecode(requestQuery($request)['query'] ?? '');

        return str_contains($q, 'queue_autoscale_scaling_actions_total') && str_contains($q, 'sum by (direction)');
    });
});

it('shows SLA breach state with predicted pickup times', function (): void {
    Http::fake([
        'prometheus.test:9090/api/v1/query_range*' => Http::response(queuesMatrix([
            ['metric' => ['queue' => 'default'], 'values' => [[1735689600, '12'], [1735689660, '45']]],
        ])),
        'prometheus.test:9090/api/v1/query?*' => function ($request) {
            $q = rawurldecode(requestQuery($request)['query'] ?? '');

            if (str_contains($q, 'sla_breach_ratio')) {
                return Http::response(queuesVector([
                    ['metric' => ['queue' => 'default'], 'value' => [1735689600, '1']],
                ]));
            }

            return Http::response(queuesVector([
                ['metric' => [], 'value' => [1735689600, '2']],
            ]));
        },
    ]);

    $this->getJson(panelUrl(AutoscaleSla::id()))
        ->assertOk()
        ->assertSee('SLA')
        ->assertSee('In breach')
        ->assertSee('Breaches');

    Http::assertSent(function ($request): bool {
        $q = rawurldecode(requestQuery($request)['query'] ?? '');

        return str_contains($q, 'queue_autoscale_sla_predicted_pickup_seconds');
    });
});

it('explains the missing cluster gauges on single-host installs', function (): void {
    // queue_autoscale_cluster_* gauges only exist in cluster mode — say so
    // instead of showing misleading "0 managers" stats.
    Http::fake([
        'prometheus.test:9090/api/v1/query_range*' => Http::response(queuesMatrix([])),
        'prometheus.test:9090/api/v1/query?*' => Http::response(queuesVector([])),
    ]);

    $this->getJson(panelUrl(AutoscaleCluster::id()))
        ->assertOk()
        ->assertDontSee('Managers')
        ->assertSee('not running in cluster mode');
});

it('charts cluster workers against demand and capacity', function (): void {
    Http::fake([
        'prometheus.test:9090/api/v1/query_range*' => Http::response(queuesMatrix([
            ['metric' => [], 'values' => [[1735689600, '10'], [1735689660, '12']]],
        ])),
        'prometheus.test:9090/api/v1/query?*' => Http::response(queuesVector([
            ['metric' => [], 'value' => [1735689600, '3']],
        ])),
    ]);

    $this->getJson(panelUrl(AutoscaleCluster::id()))
        ->assertOk()
        ->assertSee('Cluster')
        ->assertSee('Managers')
        ->assertSee('Utilization')
        ->assertSee('Hosts advised');

    Http::assertSent(function ($request): bool {
        $q = rawurldecode(requestQuery($request)['query'] ?? '');

        return str_contains($q, 'queue_autoscale_cluster_required_workers');
    });
});

it('lists jobs with outcome tones, a row drill-down and a search control', function (): void {
    Http::fake([
        'prometheus.test:9090/api/v1/query_range*' => Http::response(queuesMatrix([
            ['metric' => ['job_name' => 'App\\Jobs\\Ship', 'queue' => 'default'], 'values' => [[1735689600, '3'], [1735689660, '4']]],
        ])),
        'prometheus.test:9090/api/v1/query?*' => function ($request) {
            $q = rawurldecode(requestQuery($request)['query'] ?? '');

            $value = match (true) {
                str_contains($q, 'queue_jobs_failed_total') => '2',
                str_contains($q, 'queue_jobs_released_total') => '0',
                str_contains($q, 'duration_milliseconds_bucket') => '180',
                str_contains($q, 'duration_milliseconds_sum') => '4000',
                default => '40',
            };

            return Http::response(queuesVector([
                ['metric' => ['job_name' => 'App\\Jobs\\Ship', 'queue' => 'default'], 'value' => [1735689600, $value]],
                ['metric' => ['job_name' => 'App\\Jobs\\Mail', 'queue' => 'emails'], 'value' => [1735689600, $value]],
            ]));
        },
    ]);

    $this->getJson(panelUrl('jobs-table', ['job_search' => 'ship']))
        ->assertOk()
        ->assertJsonPath('kind', 'table')
        ->assertJsonCount(1, 'rows') // the search filtered Mail out
        ->assertJsonPath('rows.0._link', ['to' => 'entity', 'type' => 'job', 'value' => 'App\\Jobs\\Ship'])
        ->assertJsonPath('rows.0.failed.tone', 'danger')
        ->assertJsonPath('rows.0.trend.tone', 'danger')
        ->assertJsonPath('rows.0.trend.spark', [3, 4])
        ->assertJsonPath('rows.0.avg.v', '100ms') // 4000ms / 40 runs
        ->assertJsonPath('controls.0.param', 'job_search')
        ->assertJsonPath('controls.0.value', 'ship');
});

it('renders the job detail header with a back link and the recent runs as trace rows', function (): void {
    Http::fake([
        'prometheus.test:9090/api/v1/query?*' => Http::response(queuesVector([
            ['metric' => [], 'value' => [1735689600, '5']],
        ])),
        'tempo.test:3200/api/search*' => Http::response(['traces' => [
            ['traceID' => 'abcd1234abcd1234abcd1234abcd1234', 'rootServiceName' => 'demo', 'rootTraceName' => 'App\\Jobs\\Ship', 'startTimeUnixNano' => '1735689600000000000', 'durationMs' => 1500],
        ]]),
    ]);

    $this->getJson(panelUrl('job-detail-header', ['job' => 'App\\Jobs\\Ship']))
        ->assertOk()
        ->assertJsonPath('kind', 'header')
        ->assertJsonPath('title', 'App\\Jobs\\Ship')
        ->assertJsonPath('back.page', 'jobs')
        ->assertJsonPath('stats.1.label', 'Failed')
        ->assertJsonPath('stats.1.tone', 'danger');

    $this->getJson(panelUrl('job-detail-traces', ['job' => 'App\\Jobs\\Ship']))
        ->assertOk()
        ->assertJsonPath('rows.0._link', ['to' => 'trace', 'id' => 'abcd1234abcd1234abcd1234abcd1234'])
        ->assertJsonPath('rows.0.duration.tone', 'warn')
        ->assertJsonPath('rows.0.id.v', 'abcd1234…');
});

it('renders the queue detail header with headline stats', function (): void {
    Http::fake([
        'prometheus.test:9090/api/v1/query?*' => Http::response(queuesVector([
            ['metric' => [], 'value' => [1735689600, '90']],
        ])),
    ]);

    $this->getJson(panelUrl('queue-detail-header', ['queue' => 'default']))
        ->assertOk()
        ->assertJsonPath('title', 'default')
        ->assertJsonPath('back.page', 'queues')
        ->assertJsonPath('stats.1.label', 'Oldest')
        ->assertJsonPath('stats.1.value', '1.5min')
        ->assertJsonPath('stats.1.tone', 'warn');
});

it('returns the backend error in the payload instead of failing the request', function (): void {
    Http::fake(['prometheus.test:9090/*' => Http::response('boom', 502)]);

    $this->getJson(panelUrl('queues-table'))
        ->assertOk()
        ->assertJsonPath('rows', [])
        ->assertJsonPath('error', fn (mixed $error): bool => is_string($error) && $error !== '');
});
