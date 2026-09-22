<?php

declare(strict_types=1);

use Cbox\TelemetryUi\Explore\Stats;
use Cbox\TelemetryUi\Panels\Ui;

it('describes each heatmap cell as a time window and a latency band', function (): void {
    $heat = Stats::heatmap([
        ['startMs' => 1_000, 'durationMs' => 5.0],
        ['startMs' => 1_000, 'durationMs' => 120.0],
        ['startMs' => 39_000, 'durationMs' => 9_000.0],
    ], 0, 40_000);

    expect($heat['width'])->toBe(1_000)
        ->and($heat['bands'][0])->toBe([0, 10])
        ->and($heat['bands'][4])->toBe([100, 250])
        ->and(end($heat['bands']))->toBe([5000, null])
        ->and(count($heat['bands']))->toBe(count($heat['ys']))
        ->and($heat['cells'])->toContain([1, 4, 1], [39, 7, 1]);
});

it('builds a from/to window link around a moment', function (): void {
    expect(Ui::around('logs', 1_000, 30, 60, ['service.name=shop']))->toBe([
        'to' => 'explore',
        'signal' => 'logs',
        'where' => ['service.name=shop'],
        'params' => ['from' => '970', 'to' => '1060'],
    ])->and(Ui::entityIndex('query'))->toBe(['to' => 'entities', 'type' => 'query']);
});
