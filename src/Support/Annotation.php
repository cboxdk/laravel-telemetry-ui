<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Support;

/**
 * A point-in-time marker rendered as a vertical line on charts (à la
 * Grafana annotations) — a deploy, a released version, a custom event.
 */
final readonly class Annotation
{
    public function __construct(
        public float $timestampMs,
        public string $label,
        public ?string $notes,
        public string $kind,
        public ?string $traceId = null,
        public string $color = '#c084fc',
    ) {}

    /**
     * ECharts markLine payload for this annotation — everything the chart's
     * hover tooltip and click-callout need to show the full detail.
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
            'color' => $this->color,
        ];
    }
}
