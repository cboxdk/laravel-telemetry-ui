<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Cards\Builtin;

use Cbox\TelemetryUi\Cards\Card;
use Cbox\TelemetryUi\Cards\Concerns\CoercesAttributes;
use Cbox\TelemetryUi\Connectors\SourceException;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;

/**
 * The request LOG: individual requests, newest first — the live-tail view
 * for production debugging. Filter down to one user or one client IP, hit
 * Live, and watch their requests arrive; every row opens the readable
 * request story in the pane. The Routes card is the grouped sibling; the
 * shared `req_view` toggle swaps between them.
 */
class RequestLog extends Card
{
    use CoercesAttributes;

    #[Url(as: 'req_view')]
    public string $view = 'routes';

    #[Url(as: 'log_ip')]
    public string $ip = '';

    #[Url(as: 'log_user')]
    public string $user = '';

    #[Url(as: 'log_path')]
    public string $path = '';

    #[Url(as: 'log_status')]
    public string $statusCode = '';

    /** Live tail: re-poll every few seconds, newest on top. On by default. */
    #[Url(as: 'live')]
    public bool $live = true;

    /**
     * The Routes/Request log toggle is a Livewire event (not a page link) so
     * flipping views never reloads the page — both sibling cards listen.
     */
    #[On('telemetry-ui:request-view-changed')]
    public function updateRequestView(string $view): void
    {
        $this->view = $view === 'log' ? 'log' : 'routes';
    }

    public function render(): View
    {
        if ($this->view !== 'log') {
            /** @var view-string $hidden */
            $hidden = 'telemetry-ui::cards.hidden';

            return view($hidden);
        }

        [$start, $end] = $this->range();

        $rows = [];
        $error = null;

        try {
            // kind=server alone excludes browser/RUM spans (they are client/
            // internal) — and `span.browser != true` would wrongly drop every
            // backend span too, since TraceQL can't evaluate a missing attr.
            $conditions = ['kind = server', ...$this->extraTraceConditions()];

            if ($this->ip !== '') {
                $conditions[] = 'span.client.address = "'.$this->escapeLabelValue($this->ip).'"';
            }

            if ($this->user !== '') {
                $conditions[] = 'span.enduser.id = "'.$this->escapeLabelValue($this->user).'"';
            }

            if ($this->path !== '') {
                $conditions[] = 'span.url.path =~ ".*'.$this->escapeLabelValue(preg_quote($this->path, '/')).'.*"';
            }

            if (preg_match('/^([1-5])xx$/', $this->statusCode, $m) === 1) {
                $conditions[] = 'span.http.response.status_code >= '.$m[1].'00 && span.http.response.status_code < '.((int) $m[1] + 1).'00';
            }

            $traceql = '{ '.$this->traceScope(implode(' && ', $conditions))
                .' } | select(span.http.request.method, span.url.path, span.http.route, span.http.response.status_code, span.client.address, span.enduser.id, span.livewire.components)';

            foreach ($this->traces()->search($traceql, $start, $end, limit: 50) as $summary) {
                $attributes = isset($summary->matchedSpans[0]) ? $summary->matchedSpans[0]->attributes : [];

                $path = $this->str($attributes['url.path'] ?? $attributes['http.route'] ?? null) ?? $summary->rootTraceName;
                $route = $this->str($attributes['http.route'] ?? null) ?? '';

                // A Livewire update URL identifies nothing — show the
                // component(s) the request actually touched instead.
                if (($livewire = $this->str($attributes['livewire.components'] ?? null)) !== null && $livewire !== '') {
                    $path = 'livewire:'.$livewire;
                } elseif (str_starts_with($route, 'livewire:')) {
                    $path = $route;
                }

                $rows[] = [
                    'traceId' => $summary->traceId,
                    'startedAt' => $summary->startedAt,
                    'durationMs' => $summary->durationMs,
                    'service' => $summary->rootServiceName,
                    'method' => $this->str($attributes['http.request.method'] ?? null) ?? '',
                    'path' => $path,
                    'status' => $this->str($attributes['http.response.status_code'] ?? null) ?? '',
                    'ip' => $this->str($attributes['client.address'] ?? null) ?? '',
                    'user' => $this->str($attributes['enduser.id'] ?? null) ?? '',
                ];
            }

            usort($rows, static fn (array $a, array $b): int => $b['startedAt'] <=> $a['startedAt']);
        } catch (SourceException $exception) {
            $error = $exception->getMessage();
        }

        /** @var view-string $view */
        $view = 'telemetry-ui::cards.request-log';

        return view($view, [
            'rows' => $rows,
            'error' => $error,
        ]);
    }

    /**
     * Extra TraceQL span conditions AND-ed into the search — a subclass
     * narrows the log to its slice (e.g. Livewire update requests only).
     *
     * @return list<string>
     */
    protected function extraTraceConditions(): array
    {
        return [];
    }
}
