<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Support;

/**
 * Classifies a visit into a low-cardinality marketing **channel** — Direct,
 * Organic search, Social, Email, Paid, Referral — the single dimension
 * marketing actually reads. Derived at read time from the referrer (and, once
 * the emitter captures them, UTM tags + ad click-ids), so it costs ZERO ingest
 * cardinality: it's computed from labels we already have, never stored as its
 * own Loki stream label. On a ClickHouse sink the same classification runs at
 * query time. This is GA4's "Default Channel Grouping" idea, kept bounded.
 *
 * Today the signal is referrer-only, so Paid is reachable only via a paid UTM
 * medium / click-id once those exist — until then paid traffic lands in
 * Organic/Referral like any other referrer. See docs/cookbook/analytics.md.
 */
final class Channel
{
    /** Search-engine referrer hosts → Organic search. */
    private const SEARCH = ['google', 'bing', 'yahoo', 'duckduckgo', 'baidu', 'yandex', 'ecosia', 'brave', 'startpage', 'qwant', 'ask', 'aol'];

    /** Social referrer hosts → Social. */
    private const SOCIAL = ['facebook', 'fb', 'instagram', 'twitter', 'x', 't.co', 'linkedin', 'lnkd', 'reddit', 'youtube', 'pinterest', 'tiktok', 'mastodon', 'threads', 'bluesky', 'bsky', 'whatsapp', 'telegram', 'snapchat', 'tumblr', 'quora'];

    /** Webmail referrer hosts → Email. */
    private const MAIL = ['mail.google', 'outlook', 'mail.yahoo', 'mail.proton', 'webmail', 'mail.'];

    /** UTM mediums that mean paid acquisition. */
    private const PAID_MEDIUMS = ['cpc', 'ppc', 'paid', 'paidsearch', 'paid-search', 'display', 'cpm', 'banner', 'retargeting'];

    /**
     * @param  string  $referrer  the referrer domain (already reduced, '' = direct)
     * @param  string  $utmMedium  the utm_medium tag, when captured ('' otherwise)
     * @param  bool  $paidClick  whether a paid ad click-id (gclid/…) was present
     * @param  list<string>  $internalHosts  the site's own hosts → Internal
     */
    public static function classify(string $referrer, string $utmMedium = '', bool $paidClick = false, array $internalHosts = []): string
    {
        $medium = strtolower(trim($utmMedium));

        if ($paidClick || in_array($medium, self::PAID_MEDIUMS, true)) {
            return 'Paid';
        }

        if ($medium === 'email' || $medium === 'newsletter' || self::hostMatches($referrer, self::MAIL)) {
            return 'Email';
        }

        if (in_array($medium, ['social', 'social-network', 'sm'], true) || self::hostMatches($referrer, self::SOCIAL)) {
            return 'Social';
        }

        if ($medium === 'organic' || self::hostMatches($referrer, self::SEARCH)) {
            return 'Organic search';
        }

        if ($referrer !== '' && self::hostMatches($referrer, $internalHosts)) {
            return 'Internal';
        }

        return $referrer === '' ? 'Direct' : 'Referral';
    }

    /**
     * Whether the referrer host matches one of the needles as a whole label —
     * "google.com"/"news.google.com" match "google" but "googleish.com" does not.
     *
     * @param  list<string>  $needles
     */
    private static function hostMatches(string $host, array $needles): bool
    {
        if ($host === '') {
            return false;
        }

        $host = strtolower($host);

        foreach ($needles as $needle) {
            if ($host === $needle
                || str_starts_with($host, $needle.'.')
                || str_contains($host, '.'.$needle.'.')
                || str_ends_with($host, '.'.$needle)) {
                return true;
            }
        }

        return false;
    }
}
