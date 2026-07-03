<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Support;

/**
 * Builds the command-palette entries (pages, services, environments) shown
 * by the ⌘K overlay, carrying the current scope/period through each link.
 */
final class PaletteCommands
{
    /**
     * Placeholder trace id (valid hex, matches the route constraint) that the
     * client swaps for a pasted trace id.
     */
    public const TRACE_SENTINEL = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    /**
     * @param  array<string, array{label: string, group: string|null, icon: string|null, detect: string|null}>  $pages
     * @param  list<string>  $services
     * @param  list<string>  $environments
     * @param  array<string, string>  $query  current period/from/to/service/env
     * @return list<array{type: string, label: string, group: string, href: string}>
     */
    public static function build(array $pages, array $services, array $environments, string $active, array $query): array
    {
        $commands = [];

        foreach ($pages as $slug => $meta) {
            $commands[] = [
                'type' => 'Page',
                'label' => $meta['label'],
                'group' => $meta['group'] ?? '',
                'href' => route('telemetry-ui.page', array_merge($query, ['page' => $slug === 'dashboard' ? null : $slug])),
            ];
        }

        $activePage = $active === 'dashboard' ? null : $active;

        foreach ($services as $service) {
            $commands[] = [
                'type' => 'Service',
                'label' => $service,
                'group' => 'scope',
                'href' => route('telemetry-ui.page', array_merge($query, ['page' => $activePage, 'service' => $service])),
            ];
        }

        foreach ($environments as $environment) {
            $commands[] = [
                'type' => 'Env',
                'label' => $environment,
                'group' => 'scope',
                'href' => route('telemetry-ui.page', array_merge($query, ['page' => $activePage, 'env' => $environment])),
            ];
        }

        return $commands;
    }

    /**
     * @param  array<string, string>  $query
     */
    public static function traceBase(string $active, array $query): string
    {
        return route('telemetry-ui.trace', array_merge($query, ['traceId' => self::TRACE_SENTINEL]));
    }
}
