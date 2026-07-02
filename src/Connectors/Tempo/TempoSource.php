<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Connectors\Tempo;

use Cbox\TelemetryUi\Connectors\ApiClient;
use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Contracts\TracesSource;
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

        foreach ($batches as $batch) {
            if (! is_array($batch)) {
                continue;
            }

            $serviceName = $this->serviceName($batch);

            foreach ($this->scopeSpans($batch) as $span) {
                $spans[] = $this->parseSpan($span, $serviceName);
            }
        }

        usort($spans, static fn (Span $a, Span $b): int => $a->startNano <=> $b->startNano);

        return new Trace($traceId, $spans);
    }

    public function tagValues(string $tag, ?string $traceql = null): array
    {
        $params = [];

        if ($traceql !== null) {
            $params['q'] = $traceql;
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
     * @param  array<array-key, mixed>  $batch
     */
    private function serviceName(array $batch): string
    {
        $resource = is_array($batch['resource'] ?? null) ? $batch['resource'] : [];
        $attributes = $this->parseAttributes(is_array($resource['attributes'] ?? null) ? $resource['attributes'] : []);

        $service = $attributes['service.name'] ?? '';

        return is_scalar($service) ? (string) $service : '';
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
            attributes: $this->parseAttributes(is_array($span['attributes'] ?? null) ? $span['attributes'] : []),
            hasError: $statusCode === 'STATUS_CODE_ERROR' || $statusCode === 2,
        );
    }

    /**
     * Flatten an OTLP-JSON attribute list ([{key, value: {stringValue: ...}}]).
     *
     * @param  array<array-key, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function parseAttributes(array $attributes): array
    {
        $parsed = [];

        foreach ($attributes as $attribute) {
            if (! is_array($attribute) || ! isset($attribute['key']) || ! is_string($attribute['key'])) {
                continue;
            }

            $parsed[$attribute['key']] = $this->parseAnyValue(
                is_array($attribute['value'] ?? null) ? $attribute['value'] : [],
            );
        }

        return $parsed;
    }

    /**
     * @param  array<array-key, mixed>  $value
     */
    private function parseAnyValue(array $value): mixed
    {
        if (array_key_exists('stringValue', $value)) {
            return (string) $value['stringValue'];
        }

        if (array_key_exists('intValue', $value)) {
            return (int) $value['intValue'];
        }

        if (array_key_exists('doubleValue', $value)) {
            return (float) $value['doubleValue'];
        }

        if (array_key_exists('boolValue', $value)) {
            return (bool) $value['boolValue'];
        }

        if (is_array($value['arrayValue'] ?? null)) {
            $values = is_array($value['arrayValue']['values'] ?? null) ? $value['arrayValue']['values'] : [];

            return array_map(
                fn (mixed $item): mixed => $this->parseAnyValue(is_array($item) ? $item : []),
                $values,
            );
        }

        if (is_array($value['kvlistValue'] ?? null)) {
            $entries = is_array($value['kvlistValue']['values'] ?? null) ? $value['kvlistValue']['values'] : [];

            return $this->parseAttributes($entries);
        }

        return null;
    }
}
