<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Cards\Builtin;

/**
 * The routes table narrowed to Livewire components: laravel-telemetry names
 * update requests "livewire:{component}", so grouping by http_route IS the
 * per-component request table — volume, status mix, latency and a
 * drill-down to each component's request-detail page.
 */
final class LivewireComponents extends RoutesTable
{
    protected function scopeMatchers(): string
    {
        return 'http_route=~"livewire:.*"';
    }

    protected function tableTitle(): string
    {
        return 'Components';
    }

    protected function tableSubtitle(): string
    {
        return 'Per-component update volume, status mix and latency — click a component for its detail page';
    }
}
