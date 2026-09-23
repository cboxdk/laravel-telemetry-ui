<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Panels\Builtin;

use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Panels\Attributes\Param;
use Cbox\TelemetryUi\Panels\Concerns\CoercesAttributes;
use Cbox\TelemetryUi\Panels\Panel;
use Cbox\TelemetryUi\Panels\Ui;
use Cbox\TelemetryUi\Queries\Compilers\TraceqlCompiler;
use Cbox\TelemetryUi\Queries\Ir\TraceCondition;
use Cbox\TelemetryUi\Queries\Ir\TraceOp;
use Cbox\TelemetryUi\Queries\Ir\TraceQuery;
use Cbox\TelemetryUi\Queries\Results\Span;
use Cbox\TelemetryUi\Queries\Results\TraceSummary;
use Cbox\TelemetryUi\Support\Format;

/**
 * Trace search driven by friendly filters (status, route, name, duration)
 * that compose TraceQL under the hood. A raw TraceQL box is available under
 * "Advanced" for power users and for deep-links from other pages (?q=).
 *
 * @phpstan-import-type Control from Ui
 */
final class TraceSearch extends Panel
{
    use CoercesAttributes;

    public static function span(): int
    {
        return 2;
    }

    #[Param('q')]
    public string $query = '';

    #[Param('status')]
    public string $status = '';

    /** '' = all, 'frontend' = browser/RUM spans, 'backend' = server-side only. */
    #[Param('source')]
    public string $source = '';

    #[Param('route')]
    public string $route = '';

    /** '' = any, or a status class: '2xx' | '3xx' | '4xx' | '5xx'. */
    #[Param('status_code')]
    public string $statusCode = '';

    #[Param('path')]
    public string $path = '';

    #[Param('ip')]
    public string $ip = '';

    #[Param('name')]
    public string $nameContains = '';

    #[Param('min_duration')]
    public int $minDurationMs = 0;

    /** @var list<int> */
    public array $durations = [0, 100, 250, 500, 1000, 5000];

    public function data(): array
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

        $table = [];

        foreach ($results as $row) {
            $link = Ui::trace($row['traceId'], $row['startedAt']);

            $table[] = [
                '_link' => $link,
                'time' => Ui::cell($row['startedAt']->format('H:i:s'), ['raw' => $row['startedAt']->getTimestamp(), 'mono' => true]),
                'service' => Ui::cell($row['service'], array_filter([
                    'badge' => $row['service'],
                    'tone' => 'info',
                    'sub' => $row['browser'] ? 'web' : null,
                ], static fn (?string $v): bool => $v !== null)),
                'root' => Ui::cell(
                    ($row['method'] !== null ? $row['method'].' ' : '').($row['target'] ?? $row['name']),
                    array_filter([
                        'link' => $link,
                        'badge' => $row['status'],
                        'tone' => $row['isError'] ? 'danger' : null,
                    ], static fn (mixed $v): bool => $v !== null),
                ),
                'duration' => Ui::cell(Format::ms($row['durationMs']), [
                    'raw' => $row['durationMs'],
                    'mono' => true,
                    'tone' => $row['durationMs'] > 1000 ? 'warn' : null,
                ]),
                'trace' => Ui::cell(substr($row['traceId'], 0, 8).'…', ['link' => $link, 'mono' => true]),
            ];
        }

        return Ui::table('Trace search', [
            Ui::col('time', 'Time'),
            Ui::col('service', 'Service'),
            Ui::col('root', 'Root span'),
            Ui::num('duration', 'Duration'),
            Ui::num('trace', 'Trace'),
        ], $table, [
            'subtitle' => 'Find requests, jobs and commands by status, route, name or duration',
            'span' => 2,
            'error' => $error,
            'empty' => 'No traces match these filters.',
            // The TraceQL actually sent to Tempo — v1's query preview.
            'note' => $effectiveQuery,
            'controls' => $this->controls(),
        ]);
    }

    /**
     * The friendly filters plus the raw TraceQL box (Advanced). A non-empty
     * `q` wins over the builder filters.
     *
     * @return list<Control>
     */
    private function controls(): array
    {
        return [
            Ui::select('status', 'Status', $this->status, self::any([
                ['value' => 'error', 'label' => 'Errors'],
                ['value' => 'ok', 'label' => 'OK'],
            ])),
            Ui::select('source', 'Source', $this->source, self::any([
                ['value' => 'frontend', 'label' => 'Frontend (browser)'],
                ['value' => 'backend', 'label' => 'Backend'],
            ])),
            Ui::select('status_code', 'Status code', $this->statusCode, self::any(array_map(
                static fn (string $class): array => ['value' => $class, 'label' => $class],
                ['2xx', '3xx', '4xx', '5xx'],
            ))),
            Ui::search('route', 'Route', $this->route, '/orders/{id}'),
            Ui::search('path', 'Path contains', $this->path, '/checkout'),
            Ui::search('ip', 'Client IP', $this->ip, '203.0.113.9'),
            Ui::search('name', 'Name contains', $this->nameContains, 'db.query, POST …'),
            Ui::select('min_duration', 'Min duration', (string) $this->minDurationMs, array_map(
                static fn (int $duration): array => ['value' => (string) $duration, 'label' => $duration === 0 ? 'any' : $duration.'ms'],
                $this->durations,
            )),
            Ui::search('q', 'TraceQL', $this->query, '{ span.http.route = "/orders" && duration > 500ms }'),
        ];
    }

    /**
     * @param  list<array{value: string, label: string}>  $options
     * @return list<array{value: string, label: string}>
     */
    private static function any(array $options): array
    {
        return [['value' => '', 'label' => 'Any'], ...$options];
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
