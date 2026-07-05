<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Cards\Builtin;

use Cbox\TelemetryUi\Cards\Card;
use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Queries\Results\Span;
use Cbox\TelemetryUi\Queries\Results\TraceSummary;
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

        // Pull the request context (method/route/status) so a row reads like a
        // request, not just a span name. A raw ?q= (deep link, drill-down, or
        // hand-typed) is forced into the viewer's scope lock — the builder path
        // is already scoped — and enriched with the same select() unless it
        // carries its own.
        $select = ' | select(span.http.request.method, span.http.route, span.http.response.status_code, span.url.path, span.browser)';

        if ($this->query !== '') {
            $raw = $this->enforceScope($this->query);
            $traceql = str_contains($raw, '| select(') ? $raw : $raw.$select;
        } else {
            $traceql = $this->buildQuery().$select;
        }

        $results = [];
        $error = null;

        try {
            foreach ($this->traces()->search($traceql, $start, $end, limit: 50) as $summary) {
                $results[] = [
                    'traceId' => $summary->traceId,
                    'service' => $summary->rootServiceName,
                    'name' => $summary->rootTraceName !== '' ? $summary->rootTraceName : '(unnamed)',
                    'durationMs' => $summary->durationMs,
                    'startedAt' => $summary->startedAt,
                    ...$this->requestContext($summary),
                ];
            }
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

    /**
     * HTTP request context off the matched (root) span, so the trace list can
     * show method + route + status instead of a bare span name.
     *
     * @return array{method: ?string, target: ?string, status: ?string, isError: bool, browser: bool}
     */
    private function requestContext(TraceSummary $summary): array
    {
        $attributes = $summary->matchedSpans[0]->attributes ?? [];
        $str = static fn (mixed $v): ?string => is_scalar($v) && (string) $v !== '' ? (string) $v : null;

        $status = $str($attributes['http.response.status_code'] ?? null);

        return [
            'method' => $str($attributes['http.request.method'] ?? null),
            'target' => $str($attributes['http.route'] ?? $attributes['url.path'] ?? null),
            'status' => $status,
            'isError' => $status !== null && (int) $status >= 500,
            'browser' => Span::attributesAreBrowser($attributes),
        ];
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
