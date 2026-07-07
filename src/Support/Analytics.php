<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Support;

use Cbox\TelemetryUi\Queries\Ir\LineFilter;
use Cbox\TelemetryUi\Queries\Ir\LineOp;
use Cbox\TelemetryUi\Queries\Results\LogEntry;

/**
 * Reads the analytics event stream that cboxdk/laravel-telemetry emits — one
 * unsampled `analytics.page_view` log record per view, carrying the visit's
 * dimensions (session, path, referrer, country, device, …) as attributes. This
 * turns that stream into visit analytics.
 *
 * On a low-traffic site the Loki query covers every view, so counts are exact;
 * at scale it's a bounded recent sample (and the real answer is a ClickHouse
 * sink behind the same cards). Uniques are distinct `session.id` — the
 * cookieless, daily-rotating hash the emitter stamps, so no PII and no cookie.
 *
 * The events are queried exactly like deploy annotations: the log line body is
 * the event name and its OTLP attributes surface as LogEntry labels.
 */
final class Analytics
{
    /** The event name the emitter writes for a page view. */
    public const PAGE_VIEW_EVENT = 'analytics.page_view';

    /** The event name the emitter writes for an engagement (visible time, scroll). */
    public const ENGAGEMENT_EVENT = 'analytics.engagement';

    /** A pipeline stage selecting page-view events (append after logSelector()). */
    public static function pageViewFilter(): LineFilter
    {
        return new LineFilter(LineOp::Contains, self::PAGE_VIEW_EVENT);
    }

    /** A pipeline stage selecting engagement events (append after logSelector()). */
    public static function engagementFilter(): LineFilter
    {
        return new LineFilter(LineOp::Contains, self::ENGAGEMENT_EVENT);
    }

    /**
     * Normalise raw page-view log entries into flat visit rows.
     *
     * @param  iterable<LogEntry>  $entries
     * @return list<array{ts: int, session: string, path: string, referrer: string, channel: string, utm_source: string, utm_medium: string, utm_campaign: string, utm_content: string, utm_term: string, country: string, region: string, city: string, device: string, os: string, browser: string, source: string}>
     */
    public static function rows(iterable $entries): array
    {
        $rows = [];

        /** @var list<string> $internalHosts */
        $internalHosts = array_values(array_filter(
            (array) config('telemetry-ui.analytics.internal_hosts', []),
            'is_string',
        ));

        foreach ($entries as $entry) {
            if (trim($entry->line) !== 'analytics.page_view') {
                continue; // ignore incidental matches; only real page-view events
            }

            $l = $entry->labels;

            $referrer = self::referrerDomain(self::label($l, 'http.request.header.referer', 'http_request_header_referer', 'document.referrer', 'document_referrer'));
            $utmMedium = self::label($l, 'analytics.utm.medium', 'analytics_utm_medium');

            $rows[] = [
                'ts' => intdiv($entry->timestampNano, 1_000_000),
                'session' => self::label($l, 'session.id', 'session_id'),
                'path' => self::label($l, 'url.path', 'url_path', 'http.route', 'http_route') ?: '(unknown)',
                'referrer' => $referrer,
                // Derived, low-cardinality marketing channel — enriched to Paid/
                // Email/… by the UTM medium + paid click-id when the emitter
                // (telemetry.analytics.utm) captures them; referrer-only otherwise.
                'channel' => Channel::classify(
                    $referrer,
                    $utmMedium,
                    self::label($l, 'analytics.click_id', 'analytics_click_id') !== '',
                    $internalHosts,
                ),
                'utm_source' => self::label($l, 'analytics.utm.source', 'analytics_utm_source'),
                'utm_medium' => $utmMedium,
                'utm_campaign' => self::label($l, 'analytics.utm.campaign', 'analytics_utm_campaign'),
                'utm_content' => self::label($l, 'analytics.utm.content', 'analytics_utm_content'),
                'utm_term' => self::label($l, 'analytics.utm.term', 'analytics_utm_term'),
                'country' => self::label($l, 'client.geo.country', 'client_geo_country'),
                'region' => self::label($l, 'client.geo.region', 'client_geo_region'),
                'city' => self::label($l, 'client.geo.city', 'client_geo_city'),
                'device' => self::label($l, 'device.type', 'device_type'),
                'os' => self::label($l, 'os.name', 'os_name'),
                'browser' => self::label($l, 'user_agent.name', 'user_agent_name'),
                'source' => self::label($l, 'analytics.source', 'analytics_source'),
            ];
        }

        return $rows;
    }

    /**
     * Distinct visitors — the cookieless daily session hash.
     *
     * @param  list<array{session: string, ...}>  $rows
     */
    public static function uniqueVisitors(array $rows): int
    {
        $sessions = [];

        foreach ($rows as $row) {
            if ($row['session'] !== '') {
                $sessions[$row['session']] = true;
            }
        }

        return count($sessions);
    }

    /**
     * Page views bucketed over [$startMs, $endMs] into a chart series
     * ([timestampMs, count] points) — the traffic trend line.
     *
     * @param  list<array{ts: int, ...}>  $rows
     * @return list<array{int, int}>
     */
    public static function viewsSeries(array $rows, int $startMs, int $endMs, int $buckets = 48): array
    {
        $span = max(1, $endMs - $startMs);
        $bucketMs = max(1, intdiv($span, $buckets));

        $counts = array_fill(0, $buckets, 0);

        foreach ($rows as $row) {
            $ts = $row['ts'];

            if ($ts < $startMs || $ts > $endMs) {
                continue;
            }

            $counts[min($buckets - 1, intdiv($ts - $startMs, $bucketMs))]++;
        }

        $series = [];
        for ($i = 0; $i < $buckets; $i++) {
            $series[] = [$startMs + $i * $bucketMs, $counts[$i]];
        }

        return $series;
    }

    /**
     * Bounce rate: the fraction of sessions with a single page view. Null when
     * there are no identified sessions.
     *
     * @param  list<array{session: string, ...}>  $rows
     */
    public static function bounceRate(array $rows): ?float
    {
        /** @var array<string, int> $perSession */
        $perSession = [];

        foreach ($rows as $row) {
            if ($row['session'] !== '') {
                $perSession[$row['session']] = ($perSession[$row['session']] ?? 0) + 1;
            }
        }

        if ($perSession === []) {
            return null;
        }

        $single = count(array_filter($perSession, static fn (int $c): bool => $c === 1));

        return $single / count($perSession);
    }

    /**
     * Average visible time (ms) across engagement events, or null if none. The
     * emitter's browser SDK sends one `analytics.engagement` event per page
     * hide with `visible_time_ms`.
     *
     * @param  iterable<LogEntry>  $entries
     */
    public static function avgEngagementMs(iterable $entries): ?float
    {
        $sum = 0.0;
        $count = 0;

        foreach ($entries as $entry) {
            if (trim($entry->line) !== 'analytics.engagement') {
                continue;
            }

            $ms = self::label($entry->labels, 'visible.time.ms', 'visible_time_ms');

            if (is_numeric($ms)) {
                $sum += (float) $ms;
                $count++;
            }
        }

        return $count > 0 ? $sum / $count : null;
    }

    /**
     * Top values of a dimension by views, with distinct visitors per value.
     * Blank values fall into the given bucket label (or are dropped when null).
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array{key: string, views: int, visitors: int}>
     */
    public static function topBy(array $rows, string $field, int $limit = 12, ?string $blank = null): array
    {
        /** @var array<string, array{views: int, sessions: array<string, true>}> $groups */
        $groups = [];

        foreach ($rows as $row) {
            $value = is_string($row[$field] ?? null) ? $row[$field] : '';

            if ($value === '') {
                if ($blank === null) {
                    continue;
                }
                $value = $blank;
            }

            $groups[$value] ??= ['views' => 0, 'sessions' => []];
            $groups[$value]['views']++;

            $session = is_string($row['session'] ?? null) ? $row['session'] : '';
            if ($session !== '') {
                $groups[$value]['sessions'][$session] = true;
            }
        }

        $out = [];
        foreach ($groups as $key => $g) {
            $out[] = ['key' => $key, 'views' => $g['views'], 'visitors' => count($g['sessions'])];
        }

        usort($out, static fn (array $a, array $b): int => $b['views'] <=> $a['views']);

        return array_slice($out, 0, $limit);
    }

    /**
     * The registrable domain of a referrer URL, or '' for direct/empty.
     */
    private static function referrerDomain(string $referrer): string
    {
        if ($referrer === '') {
            return '';
        }

        $host = parse_url($referrer, PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            return '';
        }

        return str_starts_with($host, 'www.') ? substr($host, 4) : $host;
    }

    /**
     * First non-empty of the candidate label keys (dotted or snake_case — Loki
     * flattens OTLP attribute keys and we don't want to guess wrong).
     *
     * @param  array<string, string>  $labels
     */
    private static function label(array $labels, string ...$keys): string
    {
        foreach ($keys as $key) {
            if (isset($labels[$key]) && $labels[$key] !== '') {
                return $labels[$key];
            }
        }

        return '';
    }
}
