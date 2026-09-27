<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Testing;

/**
 * Which screens the documentation shows, and what each one is for.
 *
 * One list, so a screen that changes has one place to update and a shot
 * nobody references shows up as an unused key rather than a stale PNG
 * nobody notices. Captured by `tests/Browser/CaptureDocsScreenshotsTest.php`
 * into `docs/screenshots/<key>.png`, which is committed — the docs site
 * scrapes tagged releases and cannot run a browser.
 *
 * Adding one:
 *
 *  1. an entry here, with the caption the docs will use;
 *  2. `![caption](../screenshots/<key>.png)` in the page that describes it;
 *  3. `TELEMETRY_UI_CAPTURE_SCREENSHOTS=1 vendor/bin/pest tests/Browser/CaptureDocsScreenshotsTest.php`
 *
 * Set `TELEMETRY_UI_SCREENSHOT=<key>` to recapture one instead of all.
 *
 * Never put a page's own screenshot on that page — capture it from the page
 * that refers to it, or the docs show a picture of themselves.
 */
final class ScreenshotManifest
{
    /**
     * @return array<string, array{url: string, caption: string, width: int, height: int, full: bool, charts: bool}>
     */
    public static function all(): array
    {
        return [
            'dashboard' => self::shot(
                '/telemetry-ui',
                'The dashboard: golden signals for the whole service, with the time range and scope applying to every panel on the page.',
            ),
            'requests' => self::shot(
                '/telemetry-ui/explore/requests',
                'Request explorer. Facets on the left are exact when the traces backend can aggregate server-side, and a labelled sample when it cannot.',
            ),
            'traces' => self::shot(
                '/telemetry-ui/explore/traces',
                'Trace search. Every row opens a waterfall; the filter bar compiles to TraceQL, LogQL or the store\'s own dialect depending on the connection.',
            ),
            'queries' => self::shot(
                '/telemetry-ui/p/queries',
                'Database queries grouped by fingerprint, so an N+1 shows up as one row with a high count rather than a thousand rows.',
            ),
            'exceptions' => self::shot(
                '/telemetry-ui/p/exceptions',
                'Exceptions grouped by their fingerprint, with first and last seen — the grouping is the package\'s, not a backend\'s.',
            ),
            'queues' => self::shot(
                '/telemetry-ui/p/queues',
                'Queue health: depth, wait time and throughput per queue, with the labels bounded by the classifier so a per-tenant queue name cannot explode the series count.',
            ),
            'outgoing' => self::shot(
                '/telemetry-ui/p/outgoing',
                'Outgoing HTTP by host: connection time, TLS handshake and request duration, which is where a host provisioned in the wrong zone becomes visible.',
            ),
            'logs' => self::shot(
                '/telemetry-ui/explore/logs',
                'Log explorer with live tail. The query is the same IR the other explorers use, compiled to LogQL.',
            ),
            'hosts' => self::shot(
                '/telemetry-ui/p/hosts',
                'Hosts, from the exporters running beside the application rather than from the application itself.',
                // One table and no chart, so this is the screen that caught
                // the capture waiting on a canvas for every page.
                charts: false,
            ),
            'analytics' => self::shot(
                '/telemetry-ui/p/analytics',
                'Analytics from the same span stream: page views, referrers and campaigns, with no second SDK and no cookie.',
            ),
            'system' => self::shot(
                '/telemetry-ui/p/system',
                'System metrics — CPU, memory, filesystem — carrying the semantic-convention attribute keys and units.',
            ),
        ];
    }

    /**
     * @param  bool  $charts  whether the screen draws at least one chart. The
     *                        capture waits for a canvas before shooting,
     *                        because ECharts arrives as a lazy chunk and a
     *                        shot taken when the page settles catches panels
     *                        with their numbers in place and their chart areas
     *                        blank. A table-only screen has no canvas to wait
     *                        for and must say so.
     * @return array{url: string, caption: string, width: int, height: int, full: bool, charts: bool}
     */
    private static function shot(
        string $url,
        string $caption,
        int $width = 1600,
        int $height = 1000,
        bool $full = false,
        bool $charts = true,
    ): array {
        return compact('url', 'caption', 'width', 'height', 'full', 'charts');
    }
}
