<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Cards\Builtin\Detail;

use Cbox\TelemetryUi\Cards\Card;
use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Support\Format;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;

/**
 * The header of an issue page: the exception type, its message, and the
 * headline numbers (events, users affected, first/last seen).
 */
final class ErrorGroupHeader extends Card
{
    use ScopesToGroup;

    public function render(): View
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

        /** @var view-string $view */
        $view = 'telemetry-ui::cards.detail-header';

        return view($view, [
            'title' => is_string($detail['type'] ?? null) && $detail['type'] !== '' ? $detail['type'] : 'Error group '.$this->group,
            'subtitle' => is_string($detail['message'] ?? null) ? Str::limit($detail['message'], 160) : 'Error group',
            'backUrl' => $this->pageUrl('issues'),
            'backLabel' => '← All issues',
            'error' => $error,
            'stats' => [
                ['label' => 'Events', 'value' => $stats !== null ? Format::count((float) $stats['count']).($stats['sampled'] ? '+' : '') : '—', 'tone' => 'danger'],
                ['label' => 'Users', 'value' => $stats !== null && $stats['users'] > 0 ? Format::count((float) $stats['users']).($stats['sampled'] ? '+' : '') : '—', 'tone' => null],
                ['label' => 'First seen', 'value' => is_string($stats['firstSeen'] ?? null) ? $stats['firstSeen'] : '—', 'tone' => 'dim'],
                ['label' => 'Last seen', 'value' => is_string($stats['lastSeen'] ?? null) ? $stats['lastSeen'] : '—', 'tone' => 'dim'],
            ],
        ]);
    }
}
