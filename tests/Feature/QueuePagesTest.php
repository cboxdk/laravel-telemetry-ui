<?php

declare(strict_types=1);

use Cbox\TelemetryUi\Cards\Builtin\AutoscaleActions;
use Cbox\TelemetryUi\Cards\Builtin\AutoscaleCluster;
use Cbox\TelemetryUi\Cards\Builtin\AutoscaleSla;
use Cbox\TelemetryUi\Cards\Builtin\AutoscaleWorkers;
use Cbox\TelemetryUi\Cards\Builtin\QueueBacklog;
use Cbox\TelemetryUi\Cards\Builtin\QueueOldestJob;
use Cbox\TelemetryUi\Cards\Builtin\QueuesTable;
use Cbox\TelemetryUi\Cards\Builtin\QueueThroughput;
use Cbox\TelemetryUi\Cards\Builtin\QueueWorkers;
use Cbox\TelemetryUi\TelemetryUiManager;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

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
        ->and($pages['queues']['group'])->toBe('Activity')
        ->and($pages['autoscale']['detect'])->toBe('queue_autoscale_.*')
        ->and($pages['autoscale']['group'])->toBe('Activity');

    expect(app(TelemetryUiManager::class)->cards('queues'))->toContain(QueueBacklog::class, QueuesTable::class)
        ->and(app(TelemetryUiManager::class)->cards('autoscale'))->toContain(AutoscaleWorkers::class, AutoscaleCluster::class);
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

    Livewire::test(QueueBacklog::class)
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

    Livewire::test(QueueThroughput::class)
        ->assertSee('Throughput')
        ->assertSee('55');

    Http::assertSent(function ($request): bool {
        $q = rawurldecode(requestQuery($request)['query'] ?? '');

        return str_contains($q, 'queue_metrics_queue_throughput_per_minute');
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

    Livewire::test(QueueOldestJob::class)
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

    Livewire::test(QueueWorkers::class)
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

    Livewire::test(QueuesTable::class)
        ->assertSee('Queues')
        ->assertSee('default')
        ->assertSee('redis')
        ->assertSee('queue-detail', false); // rows link to the queue-detail page
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

    Livewire::test(AutoscaleWorkers::class)
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
    Http::fake([
        'prometheus.test:9090/api/v1/query_range*' => Http::response(queuesMatrix([
            ['metric' => [], 'values' => [[1735689600, '1'], [1735689660, '2']]],
        ])),
        'prometheus.test:9090/api/v1/query?*' => Http::response(queuesVector([
            ['metric' => [], 'value' => [1735689600, '3']],
        ])),
    ]);

    Livewire::test(AutoscaleActions::class)
        ->assertSee('Scaling actions')
        ->assertSee('Scale up')
        ->assertSee('Scale down')
        ->assertSee('3');

    Http::assertSent(function ($request): bool {
        $q = rawurldecode(requestQuery($request)['query'] ?? '');

        return str_contains($q, 'queue_autoscale_scaling_actions_total') && str_contains($q, 'direction="scale_up"');
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

    Livewire::test(AutoscaleSla::class)
        ->assertSee('SLA')
        ->assertSee('In breach')
        ->assertSee('Breaches');

    Http::assertSent(function ($request): bool {
        $q = rawurldecode(requestQuery($request)['query'] ?? '');

        return str_contains($q, 'queue_autoscale_sla_predicted_pickup_seconds');
    });
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

    Livewire::test(AutoscaleCluster::class)
        ->assertSee('Cluster')
        ->assertSee('Managers')
        ->assertSee('Utilization')
        ->assertSee('Hosts advised');

    Http::assertSent(function ($request): bool {
        $q = rawurldecode(requestQuery($request)['query'] ?? '');

        return str_contains($q, 'queue_autoscale_cluster_required_workers');
    });
});
