<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Cards\Builtin\Statamic;

use Cbox\TelemetryUi\Cards\Card;
use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Support\Format;
use Illuminate\Contracts\View\View;

/**
 * Content inventory from the opt-in observable gauges: entries per
 * collection, assets per container, user count.
 */
final class Inventory extends Card
{
    public function render(): View
    {
        $stats = [];
        $error = null;

        try {
            $entries = $this->total('sum('.$this->metric('statamic_entries_count').')');
            $assets = $this->total('sum('.$this->metric('statamic_assets_count').')');
            $users = $this->total('sum('.$this->metric('statamic_users_count').')');
            $collections = count($this->metrics()->query('count by (collection) ('.$this->metric('statamic_entries_count').')'));

            $stats = [
                $this->stat('Entries', Format::count($entries), 'info'),
                $this->stat('Collections', (string) $collections, 'dim'),
                $this->stat('Assets', Format::count($assets), 'dim'),
                $this->stat('Users', Format::count($users), 'dim'),
            ];
        } catch (SourceException $exception) {
            $error = $exception->getMessage();
        }

        return $this->chartCard(
            title: 'Content inventory',
            series: [],
            stats: $stats,
            error: $error,
            span: 2,
            note: $error === null && $stats === []
                ? 'Inventory gauges are opt-in: enable statamic-telemetry\'s observable gauges.'
                : 'Observable gauges, sampled at scrape time.',
        );
    }
}
