<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Panels\Builtin;

use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Panels\Attributes\Param;
use Cbox\TelemetryUi\Panels\Panel;
use Cbox\TelemetryUi\Panels\Ui;
use Cbox\TelemetryUi\Queries\Ir\TraceCondition;
use Cbox\TelemetryUi\Queries\Ir\TraceOp;
use Cbox\TelemetryUi\Support\Format;

/**
 * Slowest Livewire phases (render/update/call) as detail spans from Tempo,
 * with the component (and method/property) behind each.
 */
final class LivewireSlow extends Panel
{
    #[Param('lw_min_ms')]
    public int $minMs = 50;

    public static function span(): int
    {
        return 2;
    }

    /** @var list<int> */
    public array $thresholds = [10, 50, 100, 250, 500, 1000];

    public function data(): array
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

        $table = array_map(static fn (array $row): array => [
            'component' => Ui::cell($row['component'], ['mono' => true, 'link' => Ui::trace($row['traceId'], $row['startedAt'])]),
            'phase' => Ui::cell($row['phase'], ['badge' => $row['phase']]),
            'detail' => Ui::cell($row['detail'] !== '' ? $row['detail'] : '—', ['mono' => $row['detail'] !== '']),
            'duration' => Ui::cell(Format::ms($row['durationMs']), ['raw' => $row['durationMs'], 'tone' => 'warn']),
            'when' => Ui::cell($row['startedAt']->format('H:i:s'), ['raw' => $row['startedAt']->getTimestamp(), 'mono' => true]),
            '_link' => Ui::trace($row['traceId'], $row['startedAt']),
        ], array_slice($rows, 0, 50));

        return Ui::table('Slowest components', [
            Ui::col('component', 'Component'),
            Ui::col('phase', 'Phase'),
            Ui::col('detail', 'Method / property'),
            Ui::num('duration', 'Duration'),
            Ui::num('when', 'When'),
        ], $table, array_filter([
            'subtitle' => 'Livewire render/update/call spans sampled from traces — click to open the full trace',
            'error' => $error,
            'empty' => 'No Livewire spans above '.$this->minMs.'ms in this period.',
            'note' => 'Detail spans are tail-sampled — only slow/sampled requests carry them.',
            'controls' => [Ui::select('lw_min_ms', 'Slower than', (string) $this->minMs, array_map(
                static fn (int $t): array => ['value' => (string) $t, 'label' => $t.'ms'],
                $this->thresholds,
            ))],
        ], static fn ($v): bool => $v !== null));
    }
}
