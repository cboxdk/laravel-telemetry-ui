<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Cards\Builtin;

use Cbox\TelemetryUi\Cards\Card;
use Cbox\TelemetryUi\Connectors\SourceException;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Url;

/**
 * TraceQL search: either a raw query (also deep-linked from other pages'
 * drill-downs via ?q=) or quick filters for the common cases.
 */
final class TraceSearch extends Card
{
    #[Url(as: 'q')]
    public string $query = '';

    #[Url(as: 'errors')]
    public bool $errorsOnly = false;

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
        ]);
    }

    // Quick filters build a fresh query, replacing any raw/deep-linked one.
    public function updatedErrorsOnly(): void
    {
        $this->query = '';
    }

    public function updatedMinDurationMs(): void
    {
        $this->query = '';
    }

    public function traceUrl(string $traceId): string
    {
        return route('telemetry-ui.trace', array_filter([
            'traceId' => $traceId,
            'period' => $this->period,
            'service' => $this->service,
            'env' => $this->environment,
        ]));
    }

    private function buildQuery(): string
    {
        $conditions = [];

        if ($this->errorsOnly) {
            $conditions[] = 'status = error';
        }

        if ($this->minDurationMs > 0) {
            $conditions[] = 'duration > '.$this->minDurationMs.'ms';
        }

        $scope = $this->traceScope(implode(' && ', $conditions));

        return '{ '.($scope === '' ? 'kind = server' : $scope).' }';
    }
}
