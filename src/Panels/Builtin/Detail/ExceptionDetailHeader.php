<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Panels\Builtin\Detail;

use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Panels\Panel;
use Cbox\TelemetryUi\Panels\Ui;
use Cbox\TelemetryUi\Support\Format;

/**
 * The header of an exception-detail page: the class, a link back, and how
 * often it fired in the window.
 */
final class ExceptionDetailHeader extends Panel
{
    use ScopesToException;

    public function data(): array
    {
        $metric = $this->metric('exceptions_reported_total');

        $error = null;
        $total = 0.0;

        try {
            $total = $this->total($metric->increase($this->promDuration())->sumBy());
        } catch (SourceException $exception) {
            $error = $exception->getMessage();
        }

        return Ui::header($this->exception === '' ? '(all exceptions)' : $this->exception, 'Exception detail', [
            $this->stat('Occurrences', Format::count($total), $total > 0 ? 'danger' : 'dim'),
            $this->stat('Window', $this->period()->label(), 'dim'),
        ], [
            'back' => [...Ui::page('exceptions'), 'label' => '← All exceptions'],
            'error' => $error,
            'span' => 2,
        ]);
    }
}
