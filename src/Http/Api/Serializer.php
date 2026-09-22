<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Http\Api;

use Cbox\TelemetryUi\Analysis\MetricSummary;
use Cbox\TelemetryUi\Dimensions\Dimensions;
use Cbox\TelemetryUi\Queries\Results\Issue;
use Cbox\TelemetryUi\Queries\Results\Span;
use Cbox\TelemetryUi\Queries\Results\Trace;
use Cbox\TelemetryUi\Queries\Results\TraceSummary;
use Cbox\TelemetryUi\TelemetryUiManager;

/**
 * Result DTOs → the API's JSON shapes. The DTOs stay framework-free and
 * self-contained (an arch rule); their wire format lives here.
 */
final class Serializer
{
    /**
     * @return array<string, mixed>
     */
    public static function span(Span $span): array
    {
        return [
            'spanId' => $span->spanId,
            'parentSpanId' => $span->parentSpanId,
            'name' => $span->name,
            'service' => $span->serviceName,
            'kind' => $span->kind->value,
            'startNano' => (string) $span->startNano,
            'startMs' => intdiv($span->startNano, 1_000_000),
            'durationMs' => round($span->durationMs(), 3),
            'error' => $span->hasError,
            'browser' => $span->isBrowser(),
            'summary' => $span->summary(),
            'attributes' => self::attributes($span->attributes),
            'links' => $span->links,
        ];
    }

    /**
     * Attributes as a flat string map (the SPA only ever displays them).
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, string>
     */
    public static function attributes(array $attributes): array
    {
        $out = [];

        foreach ($attributes as $key => $value) {
            $out[(string) $key] = match (true) {
                is_bool($value) => $value ? 'true' : 'false',
                is_scalar($value) => (string) $value,
                $value === null => '',
                default => (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            };
        }

        ksort($out);

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    public static function traceSummary(TraceSummary $summary): array
    {
        return [
            'traceId' => $summary->traceId,
            'service' => $summary->rootServiceName,
            'name' => $summary->rootTraceName,
            'startedAt' => $summary->startedAt->format(DATE_RFC3339_EXTENDED),
            'startMs' => (int) $summary->startedAt->format('Uv'),
            'durationMs' => round($summary->durationMs, 3),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function metricSummary(MetricSummary $summary): array
    {
        return [
            'label' => $summary->label,
            'group' => $summary->group,
            'unit' => $summary->unit,
            'current' => $summary->current,
            'avg' => $summary->avg,
            'max' => $summary->max,
            'baseline' => $summary->baseline,
            'outlier' => $summary->isOutlier(),
            'points' => $summary->points,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function issue(Issue $issue): array
    {
        return [
            'id' => $issue->id,
            'title' => $issue->title,
            'state' => $issue->state,
            'open' => $issue->isOpen(),
            'url' => $issue->url,
            'body' => $issue->body,
            'labels' => $issue->labels,
            'author' => $issue->author,
            'assignee' => $issue->assignee,
            'count' => $issue->count,
            'kind' => $issue->kind,
            'createdAt' => $issue->createdAt?->format(DATE_RFC3339),
            'updatedAt' => $issue->updatedAt?->format(DATE_RFC3339),
            'traceIds' => $issue->traceIds(),
        ];
    }

    /**
     * Links out to the host for declared dimensions present on a trace's spans.
     *
     * @return array<string, string>
     */
    public static function dimensionLinks(Trace $trace): array
    {
        $dimensions = app(TelemetryUiManager::class)->dimensions();
        $links = [];

        foreach ($trace->spans as $span) {
            $links = [...$dimensions->linksFor($span->attributes), ...$links];
        }

        return $links;
    }

    public static function dimensions(): Dimensions
    {
        return app(TelemetryUiManager::class)->dimensions();
    }
}
