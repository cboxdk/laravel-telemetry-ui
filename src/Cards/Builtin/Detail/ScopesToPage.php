<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Cards\Builtin\Detail;

use Cbox\TelemetryUi\Cards\Builtin\FrontendPages;
use Cbox\TelemetryUi\Cards\Builtin\WebVitals;
use Cbox\TelemetryUi\Queries\Ir\LabelFilter;
use Cbox\TelemetryUi\Queries\Ir\LabelMatcher;
use Cbox\TelemetryUi\Queries\Ir\LogQuery;
use Cbox\TelemetryUi\Queries\Ir\LogStage;
use Cbox\TelemetryUi\Queries\Ir\MatchOp;
use Cbox\TelemetryUi\Queries\Ir\TraceCondition;
use Livewire\Attributes\Url;

/**
 * Scopes a card to a single concrete URL path (the `?path=` from the
 * page-detail page — e.g. "/blog/launching-laravel-queue-monitor"), so a
 * reused analytics / RUM card renders that one page's numbers. Mirrors
 * {@see ScopesToRoute}, but keyed on the literal `url.path` a visit carries
 * rather than the Laravel route pattern. The query var is `path`, not `page`:
 * the dashboard route is `/{page?}`, so a `?page=` query would collide with
 * the page-slug route parameter.
 */
trait ScopesToPage
{
    #[Url(as: 'path')]
    public string $page = '';

    /**
     * The trace-scope conditions for this page (TraceQL) — matches spans on
     * both sides of the trace (the browser page load and the backend request
     * it triggered) by the concrete `url.path`. AND-joins any extra conditions.
     *
     * @return list<TraceCondition>
     */
    protected function pageTraceConditions(TraceCondition ...$extra): array
    {
        return [TraceCondition::eq('span.url.path', $this->page), ...array_values($extra)];
    }

    /**
     * LogQL pipeline stages that narrow the analytics event stream to this
     * page — the emitter stamps the concrete path as a `url_path` label. Spread
     * into {@see LogQuery::pipe()}. Empty when no
     * page is selected.
     *
     * @return list<LogStage>
     */
    protected function pageLogFilter(): array
    {
        return $this->page === ''
            ? []
            : [new LabelFilter([new LabelMatcher('url_path', MatchOp::Eq, $this->page)])];
    }

    /**
     * Whether a browser span's `http.url` resolves to this page's path.
     *
     * `url.path` only exists on the *backend* server span, so {@see pageTraceConditions()}
     * scopes a trace but never matches a browser span (`web-vitals`,
     * `document.load`, `exception`) — those carry the full `http.url`. RUM cards
     * therefore select `span.http.url` and filter in PHP, exactly like
     * {@see WebVitals} and
     * {@see FrontendPages} group by it.
     */
    protected function matchesPage(mixed $url): bool
    {
        if (! is_string($url) || $url === '') {
            return false;
        }

        $path = parse_url($url, PHP_URL_PATH);

        return (is_string($path) && $path !== '' ? $path : $url) === $this->page;
    }

    /**
     * Back to the analytics overview — where these rows drill from.
     */
    public function backUrl(): string
    {
        return $this->pageUrl('analytics');
    }
}
