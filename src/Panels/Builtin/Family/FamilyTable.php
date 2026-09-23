<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Panels\Builtin\Family;

use Cbox\TelemetryUi\Dimensions\Derivation;
use Cbox\TelemetryUi\Panels\Builtin\RoutesTable;
use Cbox\TelemetryUi\Panels\Ui;
use Cbox\TelemetryUi\TelemetryUiManager;

/**
 * The per-value table of a route family ({@see TelemetryUiManager::routeFamily()}):
 * the routes table narrowed to `hubhus:*`, with the prefix stripped so the
 * column reads as the layer's own concept — and each row opening that value's
 * page.
 *
 * @phpstan-import-type Link from Ui
 */
final class FamilyTable extends RoutesTable
{
    use ScopesToFamily;

    protected function scopeMatchers(): string
    {
        $family = $this->family();

        return $family === null ? '' : sprintf('%s=~"%s"', self::label($family['source']), $this->derivation()?->regex() ?? '.+');
    }

    protected function tableTitle(): string
    {
        return $this->family()['label'] ?? 'Routes';
    }

    protected function tableSubtitle(): string
    {
        $value = strtolower($this->family()['valueLabel'] ?? 'value');

        return "Per-{$value} volume, status mix and latency — click one for its page";
    }

    protected function routeColumnLabel(): string
    {
        return $this->family()['valueLabel'] ?? 'Route';
    }

    protected function routeValue(string $route): string
    {
        return $this->derivation()?->extract($route) ?? $route;
    }

    /**
     * @return Link
     */
    protected function routeLink(string $route): array
    {
        $family = $this->family();
        $value = $this->routeValue($route);

        return $family !== null && $family['dimension'] !== null
            ? Ui::entity($this->dimensionSlug($family['dimension']), $value)
            : Ui::entity('route', $route);
    }

    /**
     * @return array{key: string, value: string}
     */
    protected function routeDimension(string $route): array
    {
        $family = $this->family();

        return $family !== null && $family['dimension'] !== null
            ? ['key' => $family['dimension'], 'value' => $this->routeValue($route)]
            : ['key' => 'http.route', 'value' => $route];
    }

    private function dimensionSlug(string $key): string
    {
        return app(TelemetryUiManager::class)->dimensions()->resolve($key)->entitySlug();
    }

    private function derivation(): ?Derivation
    {
        $family = $this->family();

        return $family === null ? null : new Derivation($family['source'], $family['pattern']);
    }

    /** Prometheus labels are the snake_cased attribute keys. */
    private static function label(string $key): string
    {
        return str_replace(['.', '-'], '_', $key);
    }
}
