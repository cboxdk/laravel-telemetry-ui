<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Panels\Builtin\Detail;

use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Panels\Panel;
use Cbox\TelemetryUi\Panels\Ui;
use Cbox\TelemetryUi\Queries\Ir\MetricQuery;
use Cbox\TelemetryUi\Queries\Results\Sample;
use Cbox\TelemetryUi\Support\Format;

/**
 * The services running ON a host, from their own Prometheus exporters —
 * MySQL (mysqld_exporter), Redis (redis_exporter), PostgreSQL
 * (postgres_exporter), … scraped into the same Prometheus the app metrics
 * live in, so the dashboard can show them next to the host with no extra
 * plumbing.
 *
 * Config-driven (`telemetry-ui.host-services`): each service has an `up`
 * probe and a set of stat tiles, with `{host}` expanding to the escaped
 * host name — exporters label instances differently (host:port, nodename,
 * …), so the matcher lives in the query, not in code. A service whose
 * probe returns nothing simply doesn't render: fail-open, auto-detected.
 */
final class HostServices extends Panel
{
    use ScopesToMachine;

    public static function span(): int
    {
        return 2;
    }

    /**
     * @return array<string, mixed>
     */
    public function data(): array
    {
        $services = [];
        $error = null;

        /** @var array<string, array{label?: string, kind?: string, up?: string, note?: string, tiles?: array<int, array{label?: string, query?: string, unit?: string}>}> $configured */
        $configured = (array) config('telemetry-ui.host-services', []);

        $failed = [];

        try {
            foreach ($configured as $service) {
                if (! is_array($service) || ! is_string($service['up'] ?? null)) {
                    continue;
                }

                $observed = ($service['kind'] ?? 'exporter') === 'observed';

                try {
                    $up = $this->probe($service['up']);
                } catch (SourceException) {
                    // One service's probe failing (an exporter query the
                    // backend can't run) costs that section, not the panel.
                    $failed[] = (string) ($service['label'] ?? '?');

                    continue;
                }

                // An observed service shows only when there was traffic.
                if ($observed) {
                    $up = array_values(array_filter($up, static fn ($s): bool => $s->value > 0.0));
                }

                if ($up === []) {
                    continue; // exporter absent for this host — no section.
                }

                $tiles = [];

                foreach ((array) ($service['tiles'] ?? []) as $tile) {
                    if (! is_array($tile) || ! is_string($tile['query'] ?? null)) {
                        continue;
                    }

                    try {
                        $value = $this->total(MetricQuery::raw($this->expand($tile['query'])));
                    } catch (SourceException) {
                        continue; // one missing stat is one fewer tile.
                    }

                    if (is_nan($value)) {
                        continue;
                    }

                    $tiles[] = [
                        'label' => (string) ($tile['label'] ?? '?'),
                        'value' => $this->format($value, (string) ($tile['unit'] ?? '')),
                        'tone' => 'dim',
                    ];
                }

                $services[] = [
                    'label' => (string) ($service['label'] ?? '?'),
                    // 'exporter' sections have a real health probe (an *_up
                    // gauge: 1/0). 'observed' sections only know the app
                    // interacted with the service — absence of traffic is
                    // NOT downtime, so they never claim up/down.
                    'kind' => ($service['kind'] ?? 'exporter') === 'observed' ? 'observed' : 'exporter',
                    'up' => ($up[0]->value ?? 0.0) > 0.0,
                    'note' => is_string($service['note'] ?? null) ? $service['note'] : null,
                    'tiles' => $tiles,
                ];
            }
        } catch (SourceException $exception) {
            $error = $exception->getMessage();
        }

        $parts = [];

        foreach ($services as $service) {
            // 'observed' has no health probe, so quiet ≠ down: it never claims up/down.
            $badge = match (true) {
                $service['kind'] === 'observed' => ['label' => 'observed', 'tone' => 'info', 'title' => 'Measured by the app itself — no health probe, so quiet ≠ down'],
                $service['up'] => ['label' => 'up', 'tone' => 'ok'],
                default => ['label' => 'down', 'tone' => 'danger'],
            };

            $parts[] = Ui::stats($service['label'], $service['tiles'], array_filter([
                'badge' => $badge,
                'note' => $service['note'],
            ], static fn ($v): bool => $v !== null));
        }

        if ($failed !== [] && $parts === [] && $error === null) {
            $error = 'Could not probe: '.implode(', ', $failed).'.';
        }

        return Ui::composite('Services on this host', $parts, [
            'subtitle' => "From the services' own Prometheus exporters (mysqld_exporter, redis_exporter, …)",
            'span' => 2,
            'error' => $error,
            'empty' => 'No service exporters detected for this host. Point mysqld_exporter / redis_exporter / '
                .'postgres_exporter at the same Prometheus, or add your own probes under telemetry-ui.host-services.',
        ]);
    }

    /**
     * Expand the `{host}` token to the escaped host name.
     */
    private function expand(string $query): string
    {
        return str_replace('{host}', addcslashes($this->host, '"\\'), $query);
    }

    private function format(float $value, string $unit): string
    {
        return match ($unit) {
            'bytes' => Format::bytes($value),
            'ms' => Format::ms($value),
            'percent', 'ratio' => Format::percent($value),
            'raw' => rtrim(rtrim(number_format($value, 2), '0'), '.'),
            default => Format::count($value),
        };
    }

    /**
     * Run a service's health probe. Older published configs filter observed
     * traffic in PromQL (`… > 0`), which some backends (telemetryd) can't
     * evaluate — retry without the comparison; the caller filters instead.
     *
     * @return list<Sample>
     */
    private function probe(string $query): array
    {
        try {
            return $this->metrics()->query(MetricQuery::raw($this->expand($query)));
        } catch (SourceException $exception) {
            $bare = preg_replace('/\s*>\s*0(\.0+)?\s*$/', '', $query);

            if ($bare === null || $bare === $query) {
                throw $exception;
            }

            return $this->metrics()->query(MetricQuery::raw($this->expand($bare)));
        }
    }
}
