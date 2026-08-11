<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Support;

use Cbox\TelemetryUi\Connectors\ConnectionManager;
use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Contracts\EnumeratesMetricNames;
use Cbox\TelemetryUi\Contracts\MetricsSource;
use Cbox\TelemetryUi\Queries\Ir\MetricQuery;
use Cbox\TelemetryUi\TelemetryUiManager;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Throwable;

/**
 * Detects which schema families the connected backends actually contain,
 * so pages contributed for optional emitters (statamic-telemetry, queue
 * autoscalers, ...) only appear when their metrics exist.
 *
 * Every uncached pattern is resolved in ONE round trip when the metrics driver
 * declares {@see EnumeratesMetricNames} — the backend returns the metric names
 * matching any of them and each pattern is re-matched against that list here.
 * Drivers without the capability keep the original one cached instant query per
 * pattern. Results are cached per pattern either way, so a warm cache costs
 * nothing and a partly warm one only asks about what it is missing.
 *
 * Backend failures fail open (the page stays visible and renders its own error
 * states) and are never cached.
 */
final readonly class SchemaDetector
{
    public function __construct(
        private ConnectionManager $connections,
        private CacheFactory $cache,
        private int $ttl = 300,
    ) {}

    /**
     * Whether any metric matching the regex pattern exists (e.g. "statamic_.*"),
     * optionally within a PromQL scope (e.g. `service_name="checkout"`) so the
     * question becomes "does the SELECTED service emit these?" — see
     * {@see MetricScope}. Scope varies the cache key; an empty scope keeps the
     * original fleet-wide key and query.
     */
    public function hasMetricsMatching(string $pattern, string $scope = '', ?string $connection = null): bool
    {
        return $this->detect([$pattern], $scope, $connection)[$pattern] ?? true;
    }

    /**
     * Answer {@see hasMetricsMatching()} for many patterns at once, in a single
     * round trip where the driver allows it.
     *
     * This is the shape page detection actually needs: {@see
     * TelemetryUiManager::visiblePages()} asks about every registered pattern on
     * every request, and asking one at a time made the shell pay one backend
     * RTT per page.
     *
     * @param  list<string>  $patterns
     * @return array<string, bool> keyed by pattern
     */
    public function detect(array $patterns, string $scope = '', ?string $connection = null): array
    {
        $store = $this->cache->store();

        $results = [];
        $missing = [];

        foreach (array_values(array_unique($patterns)) as $pattern) {
            $cached = $store->get($this->key($pattern, $scope, $connection));

            if (is_bool($cached)) {
                $results[$pattern] = $cached;

                continue;
            }

            $missing[] = $pattern;
        }

        if ($missing === []) {
            return $results;
        }

        $decided = $this->resolve($missing, $scope, $connection);

        foreach ($missing as $pattern) {
            if (! array_key_exists($pattern, $decided)) {
                // The backend could not answer for this pattern. Fail open —
                // a broken backend must not hide pages — and cache nothing, so
                // the answer is retried on the next request.
                $results[$pattern] = true;

                continue;
            }

            $results[$pattern] = $decided[$pattern];

            $store->put($this->key($pattern, $scope, $connection), $decided[$pattern], $this->ttl);
        }

        return $results;
    }

    private function key(string $pattern, string $scope, ?string $connection): string
    {
        return 'telemetry-ui:detect:'.($connection ?? 'metrics').':'.($scope !== '' ? $scope.':' : '').$pattern;
    }

    /**
     * Ask the backend about the patterns that were not cached. Patterns the
     * backend could not answer for are ABSENT from the result rather than
     * false, so the caller can tell "no such metrics" from "no answer".
     *
     * @param  list<string>  $patterns
     * @return array<string, bool>
     */
    private function resolve(array $patterns, string $scope, ?string $connection): array
    {
        try {
            $source = $this->connections->metrics($connection);
        } catch (SourceException) {
            return [];
        }

        return $source instanceof EnumeratesMetricNames
            ? $this->fromNameList($source, $patterns, $scope)
            : $this->fromCounts($source, $patterns, $scope);
    }

    /**
     * One round trip: fetch the metric names matching any pattern, then decide
     * each pattern against that list.
     *
     * @param  list<string>  $patterns
     * @return array<string, bool>
     */
    private function fromNameList(EnumeratesMetricNames $source, array $patterns, string $scope): array
    {
        try {
            $names = $source->metricNamesMatching($patterns, $scope);
        } catch (SourceException) {
            return [];
        }

        $decided = [];

        foreach ($patterns as $pattern) {
            $matched = $this->anyNameMatches($pattern, $names);

            if ($matched !== null) {
                $decided[$pattern] = $matched;
            }
        }

        return $decided;
    }

    /**
     * The original path, kept for drivers that cannot enumerate metric names:
     * one instant count query per pattern, each failing open on its own.
     *
     * @param  list<string>  $patterns
     * @return array<string, bool>
     */
    private function fromCounts(MetricsSource $source, array $patterns, string $scope): array
    {
        $decided = [];

        foreach ($patterns as $pattern) {
            $selector = '__name__=~"'.$pattern.'"'.($scope !== '' ? ','.$scope : '');

            try {
                $samples = $source->query(MetricQuery::raw(sprintf('count({%s})', $selector)));
            } catch (SourceException) {
                continue;
            }

            $decided[$pattern] = $samples !== [] && $samples[0]->value > 0;
        }

        return $decided;
    }

    /**
     * Whether any returned name matches the pattern under PromQL `=~`
     * semantics, which are fully anchored.
     *
     * Null means "cannot tell": the pattern is not a regex this engine can
     * evaluate, and a backend would have rejected it too. The caller fails it
     * open rather than answering "no" and hiding a page over a bad pattern.
     *
     * @param  list<string>  $names
     */
    private function anyNameMatches(string $pattern, array $names): ?bool
    {
        $regex = $this->regex($pattern);

        if ($regex === null) {
            return null;
        }

        foreach ($names as $name) {
            if (preg_match($regex, $name) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * The pattern as an anchored PCRE, or null when it will not compile.
     */
    private function regex(string $pattern): ?string
    {
        $delimiter = null;

        foreach (['/', '#', '~', '%', '@', '!'] as $candidate) {
            if (! str_contains($pattern, $candidate)) {
                $delimiter = $candidate;

                break;
            }
        }

        if ($delimiter === null) {
            return null;
        }

        $regex = $delimiter.'^(?:'.$pattern.')$'.$delimiter.'D';

        try {
            // A pattern that will not compile makes preg_match return false —
            // and, under Laravel's error handler, raises the PCRE warning as an
            // ErrorException first. Both mean the same thing here.
            return preg_match($regex, '') === false ? null : $regex;
        } catch (Throwable) {
            return null;
        }
    }
}
