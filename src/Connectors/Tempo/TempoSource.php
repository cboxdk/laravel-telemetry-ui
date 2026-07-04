<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Connectors\Tempo;

use Cbox\TelemetryUi\Connectors\ApiClient;
use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Contracts\TracesSource;
use Cbox\TelemetryUi\Queries\Results\MatchedSpan;
use Cbox\TelemetryUi\Queries\Results\Span;
use Cbox\TelemetryUi\Queries\Results\SpanKind;
use Cbox\TelemetryUi\Queries\Results\Trace;
use Cbox\TelemetryUi\Queries\Results\TraceSummary;
use DateTimeImmutable;
use DateTimeInterface;

/**
 * Grafana Tempo driver: TraceQL search plus OTLP-JSON trace retrieval.
 */
final readonly class TempoSource implements TracesSource
{
    public function __construct(private ApiClient $client) {}

    public function search(
        string $traceql,
        DateTimeInterface $start,
        DateTimeInterface $end,
        int $limit = 20,
    ): array {
        $response = $this->client->get('/api/search', [
            'q' => $traceql,
            'start' => $start->getTimestamp(),
            'end' => $end->getTimestamp(),
            'limit' => $limit,
        ]);

        $traces = is_array($response['traces'] ?? null) ? $response['traces'] : [];

        $summaries = [];

        foreach ($traces as $trace) {
            if (! is_array($trace) || ! isset($trace['traceID'])) {
                continue;
            }

            $startNano = (int) ($trace['startTimeUnixNano'] ?? 0);

            $summaries[] = new TraceSummary(
                traceId: (string) $trace['traceID'],
                rootServiceName: (string) ($trace['rootServiceName'] ?? ''),
                rootTraceName: (string) ($trace['rootTraceName'] ?? ''),
                startedAt: (new DateTimeImmutable('@'.intdiv($startNano, 1_000_000_000))),
                durationMs: (float) ($trace['durationMs'] ?? 0),
                matchedSpans: $this->parseMatchedSpans($trace),
            );
        }

        return $summaries;
    }

    public function trace(string $traceId): Trace
    {
        $path = '/api/traces/'.rawurlencode($traceId);

        $response = $this->client->get($path);

        // Tempo v1 returns the tempopb Trace directly ({"batches": [...]}),
        // v2 wraps it ({"trace": {"resourceSpans": [...]}}).
        $body = is_array($response['trace'] ?? null) ? $response['trace'] : $response;

        $batches = $body['batches'] ?? $body['resourceSpans'] ?? null;

        if (! is_array($batches)) {
            throw SourceException::unexpectedPayload($path, 'no batches/resourceSpans in trace body');
        }

        $spans = [];
        $services = [];

        foreach ($batches as $batch) {
            if (! is_array($batch)) {
                continue;
            }

            $resource = $this->resourceAttributes($batch);

            $serviceName = is_scalar($resource['service.name'] ?? null) ? (string) $resource['service.name'] : '';

            if ($serviceName !== '') {
                $services[$serviceName] = array_merge($services[$serviceName] ?? [], $resource);
            }

            foreach ($this->scopeSpans($batch) as $span) {
                $spans[] = $this->parseSpan($span, $serviceName);
            }
        }

        usort($spans, static fn (Span $a, Span $b): int => $a->startNano <=> $b->startNano);

        return new Trace($traceId, $spans, $services);
    }

    public function tagValues(
        string $tag,
        ?string $traceql = null,
        ?DateTimeInterface $start = null,
        ?DateTimeInterface $end = null,
        int $limit = 0,
    ): array {
        $params = [];

        if ($traceql !== null) {
            $params['q'] = $traceql;
        }

        // Bound the scan to a time window + limit so it doesn't enumerate the
        // whole retention (Tempo defaults to all blocks otherwise).
        if ($start !== null) {
            $params['start'] = $start->getTimestamp();
        }

        if ($end !== null) {
            $params['end'] = $end->getTimestamp();
        }

        if ($limit > 0) {
            $params['limit'] = $limit;
        }

        $response = $this->client->get('/api/v2/search/tag/'.rawurlencode($tag).'/values', $params);

        $tagValues = is_array($response['tagValues'] ?? null) ? $response['tagValues'] : [];

        $values = [];

        foreach ($tagValues as $value) {
            // v2 returns {type, value} objects; older Tempo returns plain strings.
            if (is_array($value) && isset($value['value'])) {
                $values[] = (string) $value['value'];
            } elseif (is_string($value)) {
                $values[] = $value;
            }
        }

        return $values;
    }

    /**
     * Spans matched by the TraceQL expression, from spanSets (v2) or the
     * legacy singular spanSet.
     *
     * @param  array<array-key, mixed>  $trace
     * @return list<MatchedSpan>
     */
    private function parseMatchedSpans(array $trace): array
    {
        $sets = is_array($trace['spanSets'] ?? null) ? $trace['spanSets'] : [];

        if ($sets === [] && is_array($trace['spanSet'] ?? null)) {
            $sets = [$trace['spanSet']];
        }

        $matched = [];

        foreach ($sets as $set) {
            if (! is_array($set) || ! is_array($set['spans'] ?? null)) {
                continue;
            }

            foreach ($set['spans'] as $span) {
                if (! is_array($span)) {
                    continue;
                }

                $matched[] = new MatchedSpan(
                    spanId: (string) ($span['spanID'] ?? $span['spanId'] ?? ''),
                    name: (string) ($span['name'] ?? ''),
                    startNano: (int) ($span['startTimeUnixNano'] ?? 0),
                    durationMs: ((int) ($span['durationNanos'] ?? 0)) / 1_000_000,
                    attributes: OtlpAttributes::parse(is_array($span['attributes'] ?? null) ? $span['attributes'] : []),
                );
            }
        }

        return $matched;
    }

    /**
     * @param  array<array-key, mixed>  $batch
     * @return array<string, mixed>
     */
    private function resourceAttributes(array $batch): array
    {
        $resource = is_array($batch['resource'] ?? null) ? $batch['resource'] : [];

        return OtlpAttributes::parse(is_array($resource['attributes'] ?? null) ? $resource['attributes'] : []);
    }

    /**
     * @param  array<array-key, mixed>  $batch
     * @return list<array<array-key, mixed>>
     */
    private function scopeSpans(array $batch): array
    {
        $groups = $batch['scopeSpans'] ?? $batch['instrumentationLibrarySpans'] ?? [];

        if (! is_array($groups)) {
            return [];
        }

        $spans = [];

        foreach ($groups as $group) {
            if (! is_array($group) || ! is_array($group['spans'] ?? null)) {
                continue;
            }

            foreach ($group['spans'] as $span) {
                if (is_array($span)) {
                    $spans[] = $span;
                }
            }
        }

        return $spans;
    }

    /**
     * @param  array<array-key, mixed>  $span
     */
    private function parseSpan(array $span, string $serviceName): Span
    {
        $status = is_array($span['status'] ?? null) ? $span['status'] : [];
        $statusCode = $status['code'] ?? null;

        $parentSpanId = $span['parentSpanId'] ?? null;
        $parentSpanId = is_string($parentSpanId) && $parentSpanId !== '' ? $parentSpanId : null;

        $kind = $span['kind'] ?? null;

        return new Span(
            spanId: (string) ($span['spanId'] ?? ''),
            parentSpanId: $parentSpanId,
            name: (string) ($span['name'] ?? ''),
            serviceName: $serviceName,
            kind: SpanKind::fromOtlp(is_int($kind) || is_string($kind) ? $kind : null),
            startNano: (int) ($span['startTimeUnixNano'] ?? 0),
            endNano: (int) ($span['endTimeUnixNano'] ?? 0),
            attributes: OtlpAttributes::parse(is_array($span['attributes'] ?? null) ? $span['attributes'] : []),
            hasError: $statusCode === 'STATUS_CODE_ERROR' || $statusCode === 2,
        );
    }
}
