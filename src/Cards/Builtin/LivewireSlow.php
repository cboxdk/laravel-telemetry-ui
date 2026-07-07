<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Cards\Builtin;

use Cbox\TelemetryUi\Cards\Card;
use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Queries\Ir\TraceCondition;
use Cbox\TelemetryUi\Queries\Ir\TraceOp;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Url;

/**
 * Slowest Livewire phases (render/update/call) as detail spans from Tempo,
 * with the component (and method/property) behind each.
 */
final class LivewireSlow extends Card
{
    #[Url(as: 'lw_min_ms')]
    public int $minMs = 50;

    /** @var list<int> */
    public array $thresholds = [10, 50, 100, 250, 500, 1000];

    public function render(): View
    {
        [$start, $end] = $this->range();

        $rows = [];
        $error = null;

        try {
            $query = $this->traceQuery(
                TraceCondition::re('name', 'livewire\\.(render|update|call)'),
                TraceCondition::token('duration', TraceOp::Gt, $this->minMs.'ms'),
            )->select('span.livewire.component', 'span.livewire.method', 'span.livewire.property');

            $results = $this->traces()->search($query, $start, $end, limit: 50);

            foreach ($results as $summary) {
                foreach ($summary->matchedSpans as $span) {
                    $component = $span->attributes['livewire.component'] ?? null;

                    if (! is_string($component) || $component === '') {
                        continue;
                    }

                    $detail = $span->attributes['livewire.method'] ?? $span->attributes['livewire.property'] ?? null;

                    $rows[] = [
                        'component' => $component,
                        'phase' => str_replace('livewire.', '', $span->name),
                        'detail' => is_string($detail) ? $detail : '',
                        'durationMs' => $span->durationMs,
                        'traceId' => $summary->traceId,
                        'startedAt' => $summary->startedAt,
                    ];
                }
            }

            usort($rows, static fn (array $a, array $b): int => $b['durationMs'] <=> $a['durationMs']);
        } catch (SourceException $exception) {
            $error = $exception->getMessage();
        }

        /** @var view-string $view */
        $view = 'telemetry-ui::cards.livewire-slow';

        return view($view, [
            'rows' => array_slice($rows, 0, 50),
            'error' => $error,
        ]);
    }

    public function traceUrl(string $traceId): string
    {
        return route('telemetry-ui.trace', ['traceId' => $traceId]);
    }
}
