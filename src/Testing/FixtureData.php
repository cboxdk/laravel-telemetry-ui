<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Testing;

use DateTimeImmutable;
use DateTimeInterface;

/**
 * Deterministic numbers for the fixture backends.
 *
 * Every screen in this package reads three backends over HTTP, which made
 * the dashboard the one part of it that no test ever rendered: the PHP suite
 * fakes the HTTP client, the vitest suite mounts components against
 * hand-written props, and neither one starts the app, boots the SPA and looks
 * at what came out. A browser test can — but only against data, and pointing
 * one at a real Tempo means a test that needs infrastructure, credentials,
 * and a backend whose contents change between runs.
 *
 * So the fixture backends answer from arithmetic. Three properties make that
 * useful rather than a stub:
 *
 * **Deterministic.** Everything is seeded from the query, so a screen renders
 * identically today and in six months. That is what makes a committed
 * screenshot and a visual assertion possible at all.
 *
 * **Shaped by the metric.** A duration comes back as seconds in a plausible
 * band, a ratio between 0 and 1, bytes as bytes. One waveform for everything
 * would render charts that plot and mean nothing — and would hide a unit bug
 * behind numbers that happen to look like numbers.
 *
 * **Complete.** Including the optional contracts, so no panel falls back to
 * an empty state and a screenshot shows a populated screen.
 *
 * It is not a simulation. Nothing connects a trace to the metrics beside it,
 * and none of it should be read as what a real service looks like.
 */
final class FixtureData
{
    /** @var list<string> */
    public const SERVICES = ['checkout', 'catalogue', 'identity', 'billing', 'search'];

    /** @var list<string> */
    public const ROUTES = [
        'GET /orders/{order}',
        'POST /checkout',
        'GET /products',
        'GET /products/{product}',
        'POST /cart/items',
        'GET /healthz',
    ];

    public function __construct(
        /**
         * Anchors every timestamp. Null follows the clock, which is what a
         * dashboard wants; pinned, a screenshot taken today matches one taken
         * a year ago.
         */
        private readonly ?DateTimeInterface $now = null,
        private readonly int $seed = 20260927,
    ) {}

    public function clock(): DateTimeInterface
    {
        return $this->now ?? new DateTimeImmutable;
    }

    /**
     * The known values of a label, by what the label evidently means.
     *
     * @return list<string>
     */
    public function labelValues(string $label): array
    {
        return match (true) {
            // Order matters, and this is where it bit: `geo.country_code`
            // contains `code`, so a facet labelled COUNTRY came back full
            // of HTTP status codes. Specific before general, always.
            str_contains($label, 'country') => ['DK', 'SE', 'DE', 'GB', 'US'],
            str_contains($label, 'city') => ['Copenhagen', 'Aarhus', 'Berlin', 'London'],
            str_contains($label, 'user') => ['u_4812', 'u_1193', 'u_7740', 'u_2056'],
            str_contains($label, 'ip') => ['198.51.100.24', '203.0.113.9', '192.0.2.77'],
            str_contains($label, 'service') => self::SERVICES,
            str_contains($label, 'route'), str_contains($label, 'target') => self::ROUTES,
            str_contains($label, 'env'), str_contains($label, 'deployment') => ['production', 'staging'],
            str_contains($label, 'status'), str_contains($label, 'code') => ['200', '201', '404', '422', '500'],
            str_contains($label, 'method') => ['GET', 'POST', 'PATCH', 'DELETE'],
            str_contains($label, 'queue') => ['default', 'emails', 'exports'],
            str_contains($label, 'host'), str_contains($label, 'instance') => ['web-1', 'web-2', 'worker-1'],
            str_contains($label, 'level'), str_contains($label, 'severity') => ['info', 'warning', 'error'],
            default => ['alpha', 'beta', 'gamma'],
        };
    }

    /**
     * The value for a metric at a moment, shaped by what the metric IS.
     *
     * @param  array<string, string>  $labels
     */
    public function value(string $metric, array $labels, int $at, bool $counting = false): float
    {
        $name = strtolower($metric);
        $seed = $this->int($name.json_encode($labels));

        // A slow wave plus a fast jitter, both deterministic in the
        // timestamp: a range query looks alive and a reload looks the same.
        $wave = 0.5 + 0.5 * sin(($at / 900.0) + ($seed % 1000) / 159.0);
        $jitter = 0.85 + 0.3 * (($this->int($seed.':'.intdiv($at, 15)) % 1000) / 1000.0);

        return match (true) {
            // A COUNT of something, whatever the something is called. The
            // query decides this, not the metric name: `increase(
            // http_server_request_duration_count[1h])` is a number of
            // requests, and shaping it from the word "duration" returned
            // 0.31 — which the dashboard rendered, correctly and
            // uselessly, as "Requests: 1".
            $counting => round(80 + 4_000 * $wave * $jitter),

            str_contains($name, 'ratio'), str_contains($name, 'utilization'),
            str_contains($name, 'saturation') => round(min(0.99, 0.05 + 0.5 * $wave), 4),

            // SECONDS, because laravel-telemetry 3.0 emits seconds. A fixture
            // still speaking milliseconds here would quietly validate a
            // dashboard that is wrong, which is the one thing it must not do.
            str_contains($name, 'duration'), str_contains($name, 'latency'),
            str_contains($name, '.time') => round((0.004 + 0.75 * $wave * $wave) * $jitter, 6),

            str_contains($name, 'bytes'), str_contains($name, 'memory'),
            str_contains($name, 'usage') => round((64 + 900 * $wave) * 1_048_576 * $jitter),

            str_contains($name, 'error'), str_contains($name, 'failed'),
            str_contains($name, 'dropped') => round(max(0.0, 12 * $wave * $jitter - 4), 3),

            str_contains($name, 'depth'), str_contains($name, 'lag'),
            str_contains($name, 'pending') => round(40 * $wave * $jitter),

            default => round((30 + 900 * $wave) * $jitter, 3),
        };
    }

    /**
     * A value in [$min, $max], stable for the key.
     */
    public function band(string $key, float $min, float $max): float
    {
        return $min + ($max - $min) * (($this->int($key) % 10_000) / 10_000.0);
    }

    /**
     * @template T
     *
     * @param  list<T>  $values
     * @return T
     */
    public function one(string $key, array $values): mixed
    {
        return $values[$this->int($key) % count($values)];
    }

    public function id(string $of, int $length = 32): string
    {
        return substr(hash('xxh128', $this->seed.':'.$of), 0, $length);
    }

    public function int(string $of): int
    {
        return (int) hexdec(substr(hash('xxh128', $this->seed.':'.$of), 0, 8));
    }
}
