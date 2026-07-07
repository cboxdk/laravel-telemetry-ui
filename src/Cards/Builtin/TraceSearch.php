<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Cards\Builtin;

use Cbox\TelemetryUi\Cards\Card;
use Cbox\TelemetryUi\Cards\Concerns\CoercesAttributes;
use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Queries\Compilers\TraceqlCompiler;
use Cbox\TelemetryUi\Queries\Ir\TraceCondition;
use Cbox\TelemetryUi\Queries\Ir\TraceOp;
use Cbox\TelemetryUi\Queries\Ir\TraceQuery;
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
    use CoercesAttributes;

    #[Url(as: 'q')]
    public string $query = '';

    #[Url(as: 'status')]
    public string $status = '';

    /** '' = all, 'frontend' = browser/RUM spans, 'backend' = server-side only. */
    #[Url(as: 'source')]
    public string $source = '';

    #[Url(as: 'route')]
    public string $route = '';

    /** '' = any, or a status class: '2xx' | '3xx' | '4xx' | '5xx'. */
    #[Url(as: 'status_code')]
    public string $statusCode = '';

    #[Url(as: 'path')]
    public string $path = '';

    #[Url(as: 'ip')]
    public string $ip = '';

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
        $selectFields = ['span.http.request.method', 'span.http.route', 'span.http.response.status_code', 'span.url.path', 'span.browser'];

        if ($this->query !== '') {
            $raw = $this->enforceScope($this->query);
            $query = TraceQuery::raw(str_contains($raw, '| select(')
                ? $raw
                : $raw.' | select('.implode(', ', $selectFields).')');
        } else {
            $query = $this->buildQuery()->select(...$selectFields);
        }

        $effectiveQuery = (new TraceqlCompiler)->compile($query);

        $results = [];
        $error = null;

        try {
            foreach ($this->traces()->search($query, $start, $end, limit: 50) as $summary) {
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
            'effectiveQuery' => $effectiveQuery,
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

    public function updatedStatusCode(): void
    {
        $this->query = '';
    }

    public function updatedPath(): void
    {
        $this->query = '';
    }

    public function updatedIp(): void
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
        $attributes = $this->requestSpanAttributes($summary);

        $status = $this->str($attributes['http.response.status_code'] ?? null);

        return [
            'method' => $this->str($attributes['http.request.method'] ?? null),
            'target' => $this->str($attributes['http.route'] ?? $attributes['url.path'] ?? null),
            'status' => $status,
            'isError' => $status !== null && (int) $status >= 500,
            'browser' => Span::attributesAreBrowser($attributes),
        ];
    }

    /**
     * The matched span that actually carries request context — a scoped query
     * (service/env selected or locked) drops the `kind = server` filter, so
     * matchedSpans[0] may be an internal/client span. Prefer the first span with
     * an HTTP method/route; fall back to the first matched span.
     *
     * @return array<string, mixed>
     */
    private function requestSpanAttributes(TraceSummary $summary): array
    {
        foreach ($summary->matchedSpans as $span) {
            if (isset($span->attributes['http.request.method']) || isset($span->attributes['http.route'])) {
                return $span->attributes;
            }
        }

        return $summary->matchedSpans[0]->attributes ?? [];
    }

    private function buildQuery(): TraceQuery
    {
        $conditions = [];

        if ($this->status === 'error') {
            $conditions[] = TraceCondition::token('status', TraceOp::Eq, 'error');
        } elseif ($this->status === 'ok') {
            $conditions[] = TraceCondition::token('status', TraceOp::Neq, 'error');
        }

        // Frontend vs backend: browser/RUM spans carry the server-stamped
        // `browser=true` attribute (they share the backend's service.name).
        if ($this->source === 'frontend') {
            $conditions[] = TraceCondition::token('span.browser', TraceOp::Eq, 'true');
        } elseif ($this->source === 'backend') {
            // NOT `span.browser != true`: TraceQL cannot evaluate a missing
            // attribute, so that would silently exclude every backend span.
            // Server-kind spans are backend by definition.
            $conditions[] = TraceCondition::token('kind', TraceOp::Eq, 'server');
        }

        if ($this->route !== '') {
            $conditions[] = TraceCondition::eq('span.http.route', $this->route);
        }

        // A status CLASS (5xx) is what you actually hunt by.
        if (preg_match('/^([1-5])xx$/', $this->statusCode, $m) === 1) {
            $conditions[] = TraceCondition::token('span.http.response.status_code', TraceOp::Gte, $m[1].'00');
            $conditions[] = TraceCondition::token('span.http.response.status_code', TraceOp::Lt, ($m[1] + 1).'00');
        }

        if ($this->path !== '') {
            $conditions[] = TraceCondition::re('span.url.path', '.*'.preg_quote($this->path, '/').'.*');
        }

        if ($this->ip !== '') {
            $conditions[] = TraceCondition::eq('span.client.address', $this->ip);
        }

        if ($this->nameContains !== '') {
            $conditions[] = TraceCondition::re('name', '.*'.$this->nameContains.'.*');
        }

        if ($this->minDurationMs > 0) {
            $conditions[] = TraceCondition::token('duration', TraceOp::Gt, $this->minDurationMs.'ms');
        }

        $query = $this->traceQuery(...$conditions);

        // Never emit `{}` (matches every span in retention): with no scope and
        // no filter, fall back to server spans — the request-shaped default.
        return $query->conditions === []
            ? $this->traceQuery(TraceCondition::token('kind', TraceOp::Eq, 'server'))
            : $query;
    }
}
