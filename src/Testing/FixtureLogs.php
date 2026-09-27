<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Testing;

use Cbox\TelemetryUi\Connectors\ProbeResult;
use Cbox\TelemetryUi\Contracts\LogsSource;
use Cbox\TelemetryUi\Contracts\ProbesConnection;
use Cbox\TelemetryUi\Queries\Ir\LogQuery;
use Cbox\TelemetryUi\Queries\Results\LogEntry;
use DateTimeInterface;

/**
 * A logs backend that answers from arithmetic. See {@see FixtureData}.
 */
final class FixtureLogs implements LogsSource, ProbesConnection
{
    /** @var list<string> */
    private const LINES = [
        'Processed order %d in 84ms',
        'Cache miss for products:index, rebuilding',
        'Charge authorised for order %d',
        'Retrying upstream after 502 (attempt %d)',
        'Session %d expired, re-authenticating',
        'Dispatched App\\Jobs\\SendReceipt for order %d',
    ];

    public function __construct(private readonly FixtureData $data = new FixtureData) {}

    public function query(
        LogQuery $query,
        DateTimeInterface $start,
        DateTimeInterface $end,
        int $limit = 100,
    ): array {
        $seed = $this->data->id($query->raw ?? (json_encode($query->stream) ?: 'logs'), 12);
        $anchor = $end->getTimestamp();
        $entries = [];

        for ($i = min($limit, 60) - 1; $i >= 0; $i--) {
            $key = "log:{$seed}:{$i}";

            $entries[] = new LogEntry(
                timestampNano: ($anchor - $i * 3) * 1_000_000_000,
                line: sprintf(
                    $this->data->one($key, self::LINES),
                    (int) $this->data->band("n:{$key}", 1_000.0, 9_999.0),
                ),
                labels: [
                    'service_name' => $this->data->one("svc:{$key}", FixtureData::SERVICES),
                    // Weighted toward info, because a log view that is all
                    // errors is not the screen anyone is looking at.
                    'level' => $this->data->one("lvl:{$key}", ['info', 'info', 'info', 'info', 'warning', 'error']),
                    'deployment_environment' => 'production',
                ],
            );
        }

        return $entries;
    }

    public function labelValues(
        string $label,
        ?DateTimeInterface $start = null,
        ?DateTimeInterface $end = null,
    ): array {
        return $this->data->labelValues($label);
    }

    public function probe(): ProbeResult
    {
        return ProbeResult::pass('fixture');
    }
}
