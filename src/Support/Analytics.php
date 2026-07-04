<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Support;

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
    /** The LogQL line filter that selects page-view events on a scope selector. */
    public const PAGE_VIEW_FILTER = ' |= "analytics.page_view"';

    /**
     * Normalise raw page-view log entries into flat visit rows.
     *
     * @param  iterable<LogEntry>  $entries
     * @return list<array{ts: int, session: string, path: string, referrer: string, country: string, device: string, browser: string, source: string}>
     */
    public static function rows(iterable $entries): array
    {
        $rows = [];

        foreach ($entries as $entry) {
            if (trim($entry->line) !== 'analytics.page_view') {
                continue; // ignore incidental matches; only real page-view events
            }

            $l = $entry->labels;

            $rows[] = [
                'ts' => intdiv($entry->timestampNano, 1_000_000),
                'session' => self::label($l, 'session.id', 'session_id'),
                'path' => self::label($l, 'url.path', 'url_path', 'http.route', 'http_route') ?: '(unknown)',
                'referrer' => self::referrerDomain(self::label($l, 'http.request.header.referer', 'http_request_header_referer', 'document.referrer', 'document_referrer')),
                'country' => self::label($l, 'client.geo.country', 'client_geo_country'),
                'device' => self::label($l, 'device.type', 'device_type'),
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
