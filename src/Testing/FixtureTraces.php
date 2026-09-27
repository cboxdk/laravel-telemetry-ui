<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Testing;

use Cbox\TelemetryUi\Connectors\ProbeResult;
use Cbox\TelemetryUi\Contracts\AggregatesSpans;
use Cbox\TelemetryUi\Contracts\ProbesConnection;
use Cbox\TelemetryUi\Contracts\TracesSource;
use Cbox\TelemetryUi\Queries\Ir\SpanAggregation;
use Cbox\TelemetryUi\Queries\Ir\TraceQuery;
use Cbox\TelemetryUi\Queries\Results\MatchedSpan;
use Cbox\TelemetryUi\Queries\Results\Span;
use Cbox\TelemetryUi\Queries\Results\SpanBucket;
use Cbox\TelemetryUi\Queries\Results\SpanKind;
use Cbox\TelemetryUi\Queries\Results\Trace;
use Cbox\TelemetryUi\Queries\Results\TraceSummary;
use DateTimeImmutable;
use DateTimeInterface;

/**
 * A traces backend that answers from arithmetic. See {@see FixtureData}.
 *
 * Implements AggregatesSpans as well, because the explorers take a very
 * different path when a backend can aggregate server-side — and a fixture
 * that only supported the read-side sample would be exercising the fallback
 * rather than the path production takes.
 */
final class FixtureTraces implements AggregatesSpans, ProbesConnection, TracesSource
{
    public function __construct(private readonly FixtureData $data = new FixtureData) {}

    public function search(
        TraceQuery $query,
        DateTimeInterface $start,
        DateTimeInterface $end,
        int $limit = 20,
    ): array {
        $seed = $this->fingerprint($query);
        $anchor = $end->getTimestamp();
        $summaries = [];

        for ($i = 0; $i < min($limit, 25); $i++) {
            $traceId = $this->data->id("trace:{$seed}:{$i}");
            $service = $this->data->one("svc:{$traceId}", FixtureData::SERVICES);
            $route = $this->data->one("route:{$traceId}", FixtureData::ROUTES);
            $durationMs = $this->data->band("dur:{$traceId}", 4.0, 1_800.0);
            $startedAt = $anchor - $i * 37 - 5;

            $summaries[] = new TraceSummary(
                traceId: $traceId,
                rootServiceName: $service,
                rootTraceName: $route,
                startedAt: new DateTimeImmutable('@'.$startedAt),
                durationMs: $durationMs,
                matchedSpans: [new MatchedSpan(
                    spanId: substr($traceId, 0, 16),
                    name: $route,
                    startNano: $startedAt * 1_000_000_000,
                    durationMs: $durationMs,
                    attributes: [
                        'http.route' => $route,
                        'service.name' => $service,
                        ...$this->selected($query, $traceId),
                    ],
                )],
            );
        }

        return $summaries;
    }

    public function trace(string $traceId): Trace
    {
        $rootService = $this->data->one("svc:{$traceId}", FixtureData::SERVICES);
        $route = $this->data->one("route:{$traceId}", FixtureData::ROUTES);
        $rootMs = $this->data->band("dur:{$traceId}", 40.0, 900.0);
        $startNano = ($this->data->clock()->getTimestamp() - 12) * 1_000_000_000;
        $rootId = substr($traceId, 0, 16);

        $spans = [new Span(
            spanId: $rootId,
            parentSpanId: null,
            name: $route,
            serviceName: $rootService,
            kind: SpanKind::Server,
            startNano: $startNano,
            endNano: $startNano + (int) ($rootMs * 1_000_000),
            attributes: [
                'http.route' => $route,
                'http.request.method' => explode(' ', $route)[0],
                'http.response.status_code' => '200',
                'service.name' => $rootService,
            ],
            hasError: false,
        )];

        // A waterfall that lays out: children fit inside the root, in order,
        // each starting after the last one ended.
        $children = 3 + (int) $this->data->band("kids:{$traceId}", 0.0, 5.0);
        $cursor = $startNano + 1_500_000;

        for ($i = 0; $i < $children; $i++) {
            $key = "child:{$traceId}:{$i}";
            $childMs = $this->data->band($key, 1.0, max(2.0, $rootMs / 3));
            $isDb = $i % 2 === 0;
            $failed = $i === $children - 1 && $this->data->int("fail:{$traceId}") % 4 === 0;

            $spans[] = new Span(
                spanId: substr($this->data->id($key), 0, 16),
                parentSpanId: $rootId,
                name: $isDb ? 'SELECT orders' : 'GET api.upstream.test/v1/rates',
                serviceName: $isDb ? $rootService : $this->data->one("dep:{$key}", FixtureData::SERVICES),
                kind: $isDb ? SpanKind::Client : SpanKind::Client,
                startNano: $cursor,
                endNano: $cursor + (int) ($childMs * 1_000_000),
                attributes: $isDb
                    ? ['db.system.name' => 'mysql', 'db.query.text' => 'select * from orders where id = ?']
                    : ['url.full' => 'https://api.upstream.test/v1/rates', 'http.response.status_code' => $failed ? '502' : '200'],
                hasError: $failed,
            );

            $cursor += (int) ($childMs * 1_000_000) + 250_000;
        }

        // Resource attributes per service name, which is what the trace
        // view reads to label each lane.
        $services = [];

        foreach ($spans as $span) {
            $services[$span->serviceName] ??= [
                'service.name' => $span->serviceName,
                'deployment.environment.name' => 'production',
            ];
        }

        return new Trace($traceId, $spans, $services);
    }

    public function tagValues(
        string $tag,
        ?TraceQuery $filter = null,
        ?DateTimeInterface $start = null,
        ?DateTimeInterface $end = null,
        int $limit = 0,
    ): array {
        $values = $this->data->labelValues($tag);

        return $limit > 0 ? array_slice($values, 0, $limit) : $values;
    }

    public function aggregateSpans(SpanAggregation $aggregation, DateTimeInterface $start, DateTimeInterface $end): array
    {
        $buckets = [];

        // `span.db.query.text` names the same attribute as `db.query.text`;
        // asking for values of the scoped spelling matched nothing and the
        // table grouped real query spans under alpha, beta and gamma.
        $groupBy = (string) preg_replace('/^(span|resource|event|instrumentation)\./', '', $aggregation->groupBy);

        foreach (array_slice($this->data->labelValues($groupBy), 0, $aggregation->limit) as $key) {
            $seed = $groupBy.':'.$key;
            // Weighted by what the key MEANS, the same way the metrics fixture
            // shapes a series: an unweighted band gave the status-code facet
            // 3.4k server errors beside 3.4k successes.
            // A narrow band, so the WEIGHT is what orders the facet. A wide
            // one swamped it: GET, the most common method there is, came
            // fourth behind DELETE.
            $count = (int) round((800 + $this->data->band("n:{$seed}", 0.0, 2_400.0)) * $this->data->weight('', [$groupBy => $key]));
            $avgMs = $this->data->band("avg:{$seed}", 2.0, 260.0);

            $buckets[] = new SpanBucket(
                key: $key,
                count: $count,
                avgMs: $avgMs,
                p95Ms: $avgMs * $this->data->band("p95:{$seed}", 1.6, 4.0),
                maxMs: $avgMs * $this->data->band("max:{$seed}", 4.0, 12.0),
                totalMs: $avgMs * $count,
                attributes: [$groupBy => $key],
            );
        }

        return $buckets;
    }

    public function probe(): ProbeResult
    {
        return ProbeResult::pass('fixture');
    }

    /**
     * The attributes the query asked for, which is the whole point of a
     * select: a card that reads `span.db.query.text` off the matched span
     * asked for it, and a backend returns what was selected.
     *
     * Ignoring it returned matched spans carrying only the route, so the slow
     * queries and browser-exception cards found nothing on every span they
     * were handed and rendered their empty states — against a fixture that had
     * answered the search with twenty-five matches.
     *
     * @return array<string, string>
     */
    private function selected(TraceQuery $query, string $traceId): array
    {
        $attributes = [];

        foreach ($query->select as $field) {
            // `span.db.query.text` and `resource.host.name` name the same
            // attribute a span carries, with the scope the dialect needs.
            $key = (string) preg_replace('/^(span|resource|event|instrumentation)\./', '', $field);

            if ($key === '') {
                continue;
            }

            $attributes[$key] = $this->data->attribute($key, $traceId);
        }

        return $attributes;
    }

    private function fingerprint(TraceQuery $query): string
    {
        return $this->data->id($query->raw ?? (json_encode($query->conditions) ?: 'traces'), 12);
    }
}
