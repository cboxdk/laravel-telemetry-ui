<?php

declare(strict_types=1);

use Cbox\TelemetryUi\Queries\Compilers\PromqlCompiler;
use Cbox\TelemetryUi\Queries\Ir\MetricQuery;

it('applies a scalar to a histogram quantile', function (): void {
    $query = (new MetricQuery('http_server_request_duration_seconds_bucket'))->quantile(0.95, '5m')->times(1000);

    expect((new PromqlCompiler)->compile($query))
        ->toBe('histogram_quantile(0.95, sum by (le) (rate(http_server_request_duration_seconds_bucket[5m]))) * 1000');
});

it('leaves a plain quantile unscaled', function (): void {
    $query = (new MetricQuery('queue_wait_seconds_bucket'))->quantile(0.5, '1m', 'queue');

    expect((new PromqlCompiler)->compile($query))
        ->toBe('histogram_quantile(0.5, sum by (queue, le) (rate(queue_wait_seconds_bucket[1m])))');
});
