<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Contracts;

use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Support\SchemaDetector;

/**
 * A {@see MetricsSource} that can list the metric names it holds in one call,
 * narrowed to a set of regex patterns and an optional scope.
 *
 * Page detection asks "does this metric family exist?" once per registered
 * pattern. Against a remote backend that is one round trip per pattern and the
 * latency dominates — sixteen built-in patterns cost sixteen RTTs before the
 * shell renders. A driver that declares this capability answers all of them at
 * once: Prometheus/Mimir from the series index
 * (`/api/v1/label/__name__/values` with a `match[]` selector), a SQL store from
 * a DISTINCT over its metric-name column.
 *
 * The capability is optional the way {@see AggregatesSpans} is optional for
 * traces: {@see SchemaDetector} feature-detects it and falls back to one
 * `count({...})` query per pattern when it is absent, so metrics drivers
 * outside this package keep working unchanged.
 *
 * @api Implement alongside MetricsSource to collapse page detection to a
 *      single round trip.
 */
interface EnumeratesMetricNames
{
    /**
     * The metric names present that match at least one of the patterns,
     * restricted to $scope when it is non-empty.
     *
     * Only names belonging to series a plain instant query would see may be
     * returned: detection means "is this family live now", so a family whose
     * series went stale must not keep its page alive. Returning a name no
     * pattern asked for is harmless — the caller re-matches each pattern
     * against the list — but omitting a live one hides a page.
     *
     * @param  list<string>  $patterns  regex patterns with PromQL `=~` semantics (fully anchored)
     * @param  string  $scope  a PromQL matcher fragment, e.g. `service_name="checkout"`
     * @return list<string>
     *
     * @throws SourceException when the backend cannot answer; detection fails
     *                         open on it rather than hiding every page.
     */
    public function metricNamesMatching(array $patterns, string $scope = ''): array;
}
