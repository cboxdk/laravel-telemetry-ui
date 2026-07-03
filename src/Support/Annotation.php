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
     * ECharts markLine payload for this annotation.
     *
     * @return array<string, mixed>
     */
    public function toMarkLine(): array
    {
        return [
            'xAxis' => $this->timestampMs,
            'label' => $this->label,
            'notes' => $this->notes,
            'color' => $this->color,
        ];
    }
}
