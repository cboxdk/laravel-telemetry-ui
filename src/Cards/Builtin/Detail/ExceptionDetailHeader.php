<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Cards\Builtin\Detail;

use Cbox\TelemetryUi\Cards\Card;
use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Support\Format;
use Illuminate\Contracts\View\View;

/**
 * The header of an exception-detail page: the class, a link back, and how
 * often it fired in the window.
 */
final class ExceptionDetailHeader extends Card
{
    use ScopesToException;

    public function render(): View
    {
        $metric = $this->metric('exceptions_reported_total');

        $error = null;
        $total = 0.0;

        try {
            $total = $this->total('sum(increase('.$metric.'['.$this->promDuration().']))');
        } catch (SourceException $exception) {
            $error = $exception->getMessage();
        }

        /** @var view-string $view */
        $view = 'telemetry-ui::cards.detail-header';

        return view($view, [
            'title' => $this->exception === '' ? '(all exceptions)' : $this->exception,
            'subtitle' => 'Exception detail',
            'backUrl' => $this->backUrl(),
            'backLabel' => '← All exceptions',
            'error' => $error,
            'stats' => [
                ['label' => 'Occurrences', 'value' => Format::count($total), 'tone' => $total > 0 ? 'danger' : 'dim'],
                ['label' => 'Window', 'value' => $this->period()->label(), 'tone' => 'dim'],
            ],
        ]);
    }

    public function backUrl(): string
    {
        return $this->pageUrl('exceptions');
    }
}
