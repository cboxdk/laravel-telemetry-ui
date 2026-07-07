<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Cards\Builtin;

use Cbox\TelemetryUi\Cards\Card;
use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Support\Analytics;
use Illuminate\Contracts\View\View;

/**
 * Campaign attribution from UTM tags on the landing URL — top campaigns,
 * sources, mediums (and, higher-cardinality, content/term) with distinct
 * visitors each. Needs the emitter's `telemetry.analytics.utm` capture on
 * (cboxdk/laravel-telemetry ≥ 0.3.0); until then it shows one empty state
 * rather than a wall of blank columns. Which columns show is
 * `telemetry-ui.analytics.dimensions`. See {@see Analytics}.
 */
final class AnalyticsCampaigns extends Card
{
    private const SAMPLE_LIMIT = 5000;

    /**
     * Config key → [row field, title, top-N limit], in display order.
     *
     * @var array<string, array{string, string, int}>
     */
    private const DIMENSIONS = [
        'campaigns' => ['utm_campaign', 'Campaigns', 12],
        'utm_sources' => ['utm_source', 'Sources', 10],
        'utm_mediums' => ['utm_medium', 'Mediums', 10],
        'utm_contents' => ['utm_content', 'Content', 10],
        'utm_terms' => ['utm_term', 'Terms', 10],
    ];

    public function render(): View
    {
        [$start, $end] = $this->range();

        /** @var array<int, array{title: string, rows: list<array{key: string, views: int, visitors: int}>}> $sections */
        $sections = [];
        $hasUtm = false;
        $error = null;

        /** @var array<string, bool> $enabled */
        $enabled = (array) config('telemetry-ui.analytics.dimensions', []);

        try {
            $rows = Analytics::rows($this->logs()->query(
                $this->logSelector().Analytics::PAGE_VIEW_FILTER,
                $start,
                $end,
                limit: self::SAMPLE_LIMIT,
            ));

            foreach (self::DIMENSIONS as $key => [$field, $title, $limit]) {
                if (($enabled[$key] ?? true) === false) {
                    continue;
                }

                $top = Analytics::topBy($rows, $field, $limit);

                if ($top !== []) {
                    $hasUtm = true;
                }

                $sections[] = ['title' => $title, 'rows' => $top];
            }
        } catch (SourceException $exception) {
            $error = $exception->getMessage();
        }

        /** @var view-string $view */
        $view = 'telemetry-ui::cards.analytics-campaigns';

        return view($view, [
            'sections' => $sections,
            'hasUtm' => $hasUtm,
            'error' => $error,
        ]);
    }
}
