<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Cards\Builtin;

use Cbox\TelemetryUi\Cards\Card;
use Cbox\TelemetryUi\Connectors\SourceException;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Url;

/**
 * Trace search driven by friendly filters (status, route, name, duration)
 * that compose TraceQL under the hood. A raw TraceQL box is available under
 * "Advanced" for power users and for deep-links from other pages (?q=).
 */
final class TraceSearch extends Card
{
    #[Url(as: 'q')]
    public string $query = '';

    #[Url(as: 'status')]
    public string $status = '';

    /** '' = all, 'frontend' = browser/RUM spans, 'backend' = server-side only. */
    #[Url(as: 'source')]
    public string $source = '';

    #[Url(as: 'route')]
    public string $route = '';

    #[Url(as: 'name')]
    public string $nameContains = '';

    #[Url(as: 'min_duration')]
    public int $minDurationMs = 0;

    /** @var list<int> */
    public array $durations = [0, 100, 250, 500, 1000, 5000];

    public function render(): View
    {
        [$start, $end] = $this->range();

        $traceql = $this->query !== '' ? $this->query : $this->buildQuery();

        $results = [];
        $error = null;

        try {
            $results = $this->traces()->search($traceql, $start, $end, limit: 50);
        } catch (SourceException $exception) {
            $error = $exception->getMessage();
        }

        /** @var view-string $view */
        $view = 'telemetry-ui::cards.trace-search';

        return view($view, [
            'results' => $results,
            'error' => $error,
            'effectiveQuery' => $traceql,
            'usingRaw' => $this->query !== '',
        ]);
    }

    // Changing any friendly filter drops a raw/deep-linked query so the
    // builder stays the source of truth.
    public function updatedStatus(): void
    {
        $this->query = '';
    }

    public function updatedSource(): void
    {
        $this->query = '';
    }

    public function updatedRoute(): void
    {
        $this->query = '';
    }

    public function updatedNameContains(): void
    {
        $this->query = '';
    }

    public function updatedMinDurationMs(): void
    {
        $this->query = '';
    }

    public function clearFilters(): void
    {
        $this->query = '';
        $this->status = '';
        $this->source = '';
        $this->route = '';
        $this->nameContains = '';
        $this->minDurationMs = 0;
    }

    public function traceUrl(string $traceId): string
    {
        return route('telemetry-ui.trace', array_filter([
            'traceId' => $traceId,
            'period' => $this->period,
            'from' => $this->from,
            'to' => $this->to,
            'service' => $this->service,
            'env' => $this->environment,
        ]));
    }

    private function buildQuery(): string
    {
        $conditions = [];

        if ($this->status === 'error') {
            $conditions[] = 'status = error';
        } elseif ($this->status === 'ok') {
            $conditions[] = 'status != error';
        }

        // Frontend vs backend: browser/RUM spans carry the server-stamped
        // `browser=true` attribute (they share the backend's service.name).
        if ($this->source === 'frontend') {
            $conditions[] = 'span.browser = true';
        } elseif ($this->source === 'backend') {
            $conditions[] = 'span.browser != true';
        }

        if ($this->route !== '') {
            $conditions[] = 'span.http.route = "'.addcslashes($this->route, '"\\').'"';
        }

        if ($this->nameContains !== '') {
            $conditions[] = 'name =~ ".*'.addcslashes($this->nameContains, '"\\').'.*"';
        }

        if ($this->minDurationMs > 0) {
            $conditions[] = 'duration > '.$this->minDurationMs.'ms';
        }

        $scope = $this->traceScope(implode(' && ', $conditions));

        return '{ '.($scope === '' ? 'kind = server' : $scope).' }';
    }
}
