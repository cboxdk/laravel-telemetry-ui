<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Panels\Builtin\Detail;

use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Panels\Panel;
use Cbox\TelemetryUi\Panels\Ui;
use Cbox\TelemetryUi\Support\Format;
use Illuminate\Support\Str;

/**
 * The header of an issue page: the exception type, its message, and the
 * headline numbers (events, users affected, first/last seen).
 */
final class ErrorGroupHeader extends Panel
{
    use ScopesToGroup;

    public function data(): array
    {
        $error = null;
        $stats = null;
        $detail = null;

        try {
            $report = $this->groupReport();
            $stats = $report['stats'];
            $detail = $report['detail'];
        } catch (SourceException $exception) {
            $error = $exception->getMessage();
        }

        $plus = $stats !== null && $stats['sampled'] ? '+' : '';

        return Ui::header(
            $detail !== null && $detail['type'] !== '' ? $detail['type'] : 'Error group '.$this->group,
            $detail !== null ? Str::limit($detail['message'], 160) : 'Error group',
            [
                $this->stat('Events', $stats !== null ? Format::count((float) $stats['count']).$plus : '—', 'danger'),
                $this->stat('Users', $stats !== null && $stats['users'] > 0 ? Format::count((float) $stats['users']).$plus : '—'),
                $this->stat('First seen', $stats['firstSeen'] ?? '—', 'dim'),
                $this->stat('Last seen', $stats['lastSeen'] ?? '—', 'dim'),
            ],
            [
                'back' => [...Ui::page('issues'), 'label' => '← All issues'],
                'error' => $error,
                'span' => 2,
            ],
        );
    }
}
