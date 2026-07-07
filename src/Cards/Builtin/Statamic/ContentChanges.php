<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Cards\Builtin\Statamic;

use Cbox\TelemetryUi\Cards\Card;
use Cbox\TelemetryUi\Connectors\SourceException;
use Illuminate\Contracts\View\View;

/**
 * Content changes broken down by type × action (entry saved, term deleted…).
 */
final class ContentChanges extends Card
{
    public function render(): View
    {
        $metric = $this->metric('statamic_content_changes_total');

        $rows = [];
        $error = null;

        try {
            foreach ($this->metrics()->query($metric->increase($this->promDuration())->sumBy('type', 'action')) as $sample) {
                if ($sample->value < 0.5) {
                    continue;
                }

                $rows[] = [
                    'type' => $sample->labels['type'] ?? '?',
                    'action' => $sample->labels['action'] ?? '?',
                    'count' => $sample->value,
                ];
            }

            usort($rows, static fn (array $a, array $b): int => $b['count'] <=> $a['count']);
        } catch (SourceException $exception) {
            $error = $exception->getMessage();
        }

        /** @var view-string $view */
        $view = 'telemetry-ui::cards.statamic-content-changes';

        return view($view, ['rows' => array_slice($rows, 0, 100), 'error' => $error]);
    }
}
