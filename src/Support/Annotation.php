<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Support;

/**
 * A point-in-time marker rendered as a vertical line on charts (à la
 * Grafana annotations) — a deploy, a released version, a custom event.
 *
 * May represent a CLUSTER: a horizontal rollout emits the same marker from
 * every host within minutes; the reader folds those into one annotation
 * with a count, the covered hosts and the rollout window (start → end).
 *
 * @phpstan-type MarkLine array<string, mixed>
 */
final readonly class Annotation
{
    /**
     * @param  list<string>  $hosts  distinct reporting hosts (bounded sample)
     */
    public function __construct(
        public float $timestampMs,
        public string $label,
        public ?string $notes,
        public string $kind,
        public ?string $traceId = null,
        public string $color = '#c084fc',
        public int $count = 1,
        public ?float $endMs = null,
        public array $hosts = [],
    ) {}

    /**
     * ECharts markLine payload for this annotation — everything the chart's
     * anchored callout needs to show the full detail.
     *
     * @return array<string, mixed>
     */
    public function toMarkLine(): array
    {
        return [
            'xAxis' => $this->timestampMs,
            'label' => $this->label,
            'notes' => $this->notes,
            'kind' => $this->kind,
            'traceId' => $this->traceId,
            'time' => date('d/m H:i:s', (int) ($this->timestampMs / 1000)),
            'timeEnd' => $this->endMs !== null && $this->endMs > $this->timestampMs
                ? date('d/m H:i:s', (int) ($this->endMs / 1000))
                : null,
            'count' => $this->count,
            'hosts' => array_slice($this->hosts, 0, 5),
            'hostCount' => count($this->hosts),
            'color' => $this->color,
        ];
    }
}
