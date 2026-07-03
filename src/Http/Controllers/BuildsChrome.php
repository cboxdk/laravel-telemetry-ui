<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Http\Controllers;

use Cbox\TelemetryUi\Support\PaletteCommands;
use Illuminate\Support\Facades\Request;

/**
 * Shared chrome (command-palette) data for the page and trace views, so the
 * layout receives it as plain props instead of computing it in Blade.
 */
trait BuildsChrome
{
    /**
     * @param  array<string, array{label: string, group: string|null, icon: string|null, detect: string|null}>  $pages
     * @param  list<string>  $services
     * @param  list<string>  $environments
     * @return array{commands: list<array{type: string, label: string, group: string, href: string}>, traceBase: string, traceSentinel: string}
     */
    protected function chrome(array $pages, array $services, array $environments, string $active): array
    {
        $query = array_filter([
            'period' => Request::query('period'),
            'from' => Request::query('from'),
            'to' => Request::query('to'),
            'service' => Request::query('service'),
            'env' => Request::query('env'),
        ], static fn ($value): bool => is_string($value) && $value !== '');

        return [
            'commands' => PaletteCommands::build($pages, $services, $environments, $active, $query),
            'traceBase' => PaletteCommands::traceBase($active, $query),
            'traceSentinel' => PaletteCommands::TRACE_SENTINEL,
        ];
    }
}
