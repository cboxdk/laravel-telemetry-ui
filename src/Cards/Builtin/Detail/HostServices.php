<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Cards\Builtin\Detail;

use Cbox\TelemetryUi\Cards\Card;
use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Support\Format;
use Illuminate\Contracts\View\View;

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
final class HostServices extends Card
{
    use ScopesToMachine;

    public function render(): View
    {
        $services = [];
        $error = null;

        /** @var array<string, array{label?: string, up?: string, tiles?: array<int, array{label?: string, query?: string, unit?: string}>}> $configured */
        $configured = (array) config('telemetry-ui.host-services', []);

        try {
            foreach ($configured as $service) {
                if (! is_array($service) || ! is_string($service['up'] ?? null)) {
                    continue;
                }

                $up = $this->metrics()->query($this->expand($service['up']));

                if ($up === []) {
                    continue; // exporter absent for this host — no section.
                }

                $tiles = [];

                foreach ((array) ($service['tiles'] ?? []) as $tile) {
                    if (! is_array($tile) || ! is_string($tile['query'] ?? null)) {
                        continue;
                    }

                    try {
                        $value = $this->total($this->expand($tile['query']));
                    } catch (SourceException) {
                        continue; // one missing stat is one fewer tile.
                    }

                    if (is_nan($value)) {
                        continue;
                    }

                    $tiles[] = [
                        'label' => (string) ($tile['label'] ?? '?'),
                        'value' => $this->format($value, (string) ($tile['unit'] ?? '')),
                    ];
                }

                $services[] = [
                    'label' => (string) ($service['label'] ?? '?'),
                    // An *_up gauge answers 1/0; a rate-filter probe answers
                    // its (positive) rate. Anything above zero means alive.
                    'up' => ($up[0]->value ?? 0.0) > 0.0,
                    'tiles' => $tiles,
                ];
            }
        } catch (SourceException $exception) {
            $error = $exception->getMessage();
        }

        /** @var view-string $view */
        $view = 'telemetry-ui::cards.host-services';

        return view($view, ['services' => $services, 'error' => $error]);
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
}
