<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Cards;

use Cbox\TelemetryUi\Cards\Concerns\BuildsCharts;
use Cbox\TelemetryUi\Cards\Concerns\ScopesQueries;
use Cbox\TelemetryUi\Connectors\ConnectionManager;
use Cbox\TelemetryUi\Contracts\IssuesSource;
use Cbox\TelemetryUi\Contracts\LogsSource;
use Cbox\TelemetryUi\Contracts\MetricsSource;
use Cbox\TelemetryUi\Contracts\TracesSource;
use Cbox\TelemetryUi\Queries\Results\Sample;
use Cbox\TelemetryUi\Support\Annotation;
use Cbox\TelemetryUi\Support\Annotations;
use Cbox\TelemetryUi\Support\Period;
use DateTimeImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Base class for all dashboard cards — the extension point for both the
 * built-in pages and third-party packages (queue autoscalers, custom spans,
 * anything that can be expressed as PromQL/TraceQL/LogQL).
 *
 * Cards share the global scope: the time period plus the service/environment
 * selected in the sidebar switcher, all synced via the query string. The two
 * engines a card reuses live in {@see ScopesQueries} (scoped PromQL/TraceQL/
 * LogQL) and {@see BuildsCharts} (the ECharts card view + stat tiles).
 *
 * @api This base and its protected scope helpers (metric(), traceScope(),
 *      logSelector(), range(), scopeMatchers(), escapeLabelValue(), the
 *      chart/stat builders) are the supported extension surface. The built-in
 *      Cards\Builtin\* implementations are not API — subclass this base.
 */
abstract class Card extends Component
{
    use BuildsCharts;
    use ScopesQueries;

    #[Url(as: 'period')]
    public string $period = '1h';

    /**
     * Custom absolute range (unix seconds); overrides the preset period
     * when both ends are present. Set by the range picker and chart zoom.
     */
    #[Url(as: 'from')]
    public string $from = '';

    #[Url(as: 'to')]
    public string $to = '';

    #[Url(as: 'service')]
    public string $service = '';

    #[Url(as: 'env')]
    public string $environment = '';

    /** True when the card is embedded on a host page (not the built-in dashboard). */
    public bool $embedded = false;

    /**
     * The dashboard page this card is rendering on ('dashboard' also covers
     * embedded widgets). Captured at mount so drill links can be suppressed
     * on a card's own page.
     */
    public string $onPage = 'dashboard';

    /**
     * The page a chartCard() drills into from the dashboard — set it on cards
     * that summarise a dedicated page (e.g. 'requests'), and the card header
     * gains a "Requests →" link there. Null = no drill link.
     */
    protected ?string $drillPage = null;

    /**
     * Cards are Livewire components, so any of them can be dropped onto a host
     * page as a widget: `<livewire:telemetry-ui.requests-activity service="cbox-web" period="24h" />`.
     * Passed scope wins over the URL. Embedded or not, a card must still pass
     * the dashboard gate — so an embedded widget can't leak data past
     * `viewTelemetryUi` (the host still controls its own page's auth on top).
     */
    public function mount(
        ?string $service = null,
        ?string $environment = null,
        ?string $period = null,
        ?string $from = null,
        ?string $to = null,
        ?bool $embedded = null,
        ?string $onPage = null,
    ): void {
        $this->embedded = $embedded ?? ($service !== null || $environment !== null || $period !== null || $from !== null || $to !== null);

        // On the dashboard the route already enforces the gate; an embedded
        // widget runs outside those routes, so it must gate itself — a card
        // dropped on a host page can't leak data past viewTelemetryUi.
        if ($this->embedded) {
            abort_unless(Gate::allows('viewTelemetryUi'), 403);
        }

        $this->service = $service ?? $this->service;
        $this->environment = $environment ?? $this->environment;
        $this->period = $period ?? $this->period;
        $this->from = $from ?? $this->from;
        $this->to = $to ?? $this->to;

        // The page view passes $onPage explicitly — lazy cards mount in a
        // later Livewire request where the page route param is long gone,
        // so reading the route here would misreport every lazy card as
        // being on the dashboard.
        $routePage = request()->route()?->parameter('page');
        $this->onPage = $onPage ?? (is_string($routePage) && $routePage !== '' ? $routePage : 'dashboard');
    }

    #[On('telemetry-ui:period-changed')]
    public function updatePeriod(string $period): void
    {
        $this->period = Period::tryFrom($period) !== null ? $period : Period::default()->value;
        $this->from = '';
        $this->to = '';
    }

    /**
     * Skeleton rendered instantly in the page shell while the card streams in
     * its own request (lazy 'on-load'), so one slow backend query never blocks
     * the whole page. Cards with heavy layout may override to match.
     */
    public function placeholder(): View
    {
        /** @var view-string $view */
        $view = 'telemetry-ui::cards.placeholder';

        return view($view);
    }

    /**
     * Auto-refresh tick from the header control; re-renders the card.
     */
    #[On('telemetry-ui:refresh')]
    public function pollRefresh(): void {}

    protected function period(): Period
    {
        return Period::tryFrom($this->period) ?? Period::default();
    }

    /**
     * @return array{DateTimeImmutable, DateTimeImmutable}
     */
    protected function range(): array
    {
        if (ctype_digit($this->from) && ctype_digit($this->to)) {
            $from = new DateTimeImmutable('@'.$this->from);
            $to = new DateTimeImmutable('@'.$this->to);

            if ($from < $to) {
                return [$from, $to];
            }
        }

        return $this->period()->range();
    }

    protected function rangeSeconds(): int
    {
        [$start, $end] = $this->range();

        return max(1, $end->getTimestamp() - $start->getTimestamp());
    }

    /**
     * The whole active range as a PromQL duration, for period totals.
     */
    protected function promDuration(): string
    {
        return $this->rangeSeconds().'s';
    }

    protected function rateWindow(): string
    {
        return Period::windowFor($this->rangeSeconds());
    }

    protected function metrics(?string $connection = null): MetricsSource
    {
        return app(ConnectionManager::class)->metrics($connection);
    }

    protected function traces(?string $connection = null): TracesSource
    {
        return app(ConnectionManager::class)->traces($connection);
    }

    protected function logs(?string $connection = null): LogsSource
    {
        return app(ConnectionManager::class)->logs($connection);
    }

    protected function issues(?string $connection = null): IssuesSource
    {
        return app(ConnectionManager::class)->issues($connection);
    }

    /**
     * Deploy (and other configured) markers within the active range and
     * scope, for drawing as chart annotation lines. Shared across cards via
     * the Annotations cache, so a page of charts costs one lookup.
     *
     * @return list<Annotation>
     */
    protected function annotations(): array
    {
        [$start, $end] = $this->range();

        // Reuse the same scoped Loki selector every log query uses, so deploy
        // markers respect the exact service/env scope (single, multi-value
        // alternation, or a fail-closed lock) — not a single-value special case.
        // Cards always ship the FULL annotation set; the header's ⚑ toggle
        // hides marker types client-side (by kind), so toggling never costs
        // a backend refetch.
        return app(Annotations::class)->between($start, $end, $this->logSelector());
    }

    /**
     * Annotations shaped for the chart component's ECharts markLine.
     *
     * @return list<array<string, mixed>>
     */
    protected function annotationMarks(): array
    {
        return array_map(
            static fn (Annotation $annotation): array => $annotation->toMarkLine(),
            $this->annotations(),
        );
    }

    /**
     * A URL to another dashboard page carrying the current scope (period, range,
     * service, env) plus any extra query params — the one place row drill-downs
     * build their links.
     *
     * @param  array<string, string|null>  $extra
     */
    protected function pageUrl(string $page, array $extra = []): string
    {
        // Extra params first, then the scope keys — so a stray extra key can
        // never override the enforced scope (page/period/range/service/env).
        // Drop only null/'' (a legitimate '0' id or param survives).
        return route('telemetry-ui.page', array_filter([
            ...$extra,
            'page' => $page,
            'period' => $this->period,
            'from' => $this->from,
            'to' => $this->to,
            'service' => $this->service,
            'env' => $this->environment,
        ], static fn (mixed $value): bool => $value !== null && $value !== ''));
    }

    /**
     * Sum of all samples of an instant query result — the "total over the
     * period" building block for stat headers.
     *
     * @param  list<Sample>  $samples
     */
    protected function sumSamples(array $samples): float
    {
        $total = 0.0;

        foreach ($samples as $sample) {
            $total += $sample->value;
        }

        return $total;
    }

    /**
     * Evaluate an instant query and sum all its samples.
     */
    protected function total(string $promql): float
    {
        return $this->sumSamples($this->metrics()->query($promql));
    }

    /**
     * PromQL for a counter's total increase over a window, INCLUDING series
     * born inside it. increase() interpolates between samples, so a counter
     * that first appeared mid-window contributes nothing — which zeroes out
     * sparse event counters (scaling actions, SLA breaches). Series absent
     * at the window start fall back to their own zero ($selector * 0), so
     * the birth jump counts; clamp_min guards counter resets.
     *
     * @param  string  $selector  a full metric selector (already scoped)
     * @param  string|null  $window  PromQL duration; defaults to the period
     */
    protected function counterIncrease(string $selector, ?string $window = null): string
    {
        $window ??= $this->promDuration();

        return 'clamp_min('.$selector.' - ('.$selector.' offset '.$window.' or '.$selector.' * 0), 0)';
    }

    /**
     * Run a range query and reduce each series to a flat list of values,
     * keyed by a caller-built key over its labels — the per-row trend data
     * behind table sparklines.
     *
     * @param  callable(array<string, string>): string  $key
     * @return array<string, list<float>>
     */
    protected function trendByKey(string $promql, DateTimeImmutable $start, DateTimeImmutable $end, callable $key): array
    {
        $trends = [];

        foreach ($this->metrics()->queryRange($promql, $start, $end) as $series) {
            $trends[$key($series->labels)] = array_map(
                static fn ($point): float => $point->value,
                $series->points,
            );
        }

        return $trends;
    }
}
