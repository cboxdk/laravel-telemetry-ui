<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Panels\Builtin;

use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Panels\ListsRows;
use Cbox\TelemetryUi\Panels\Panel;
use Cbox\TelemetryUi\Panels\Ui;
use Cbox\TelemetryUi\Support\Analytics;
use Cbox\TelemetryUi\Support\DisplayTime;

/**
 * Each page view, newest first: which page, where the visitor came from, and
 * what they were on. The rankings above say what is popular; these rows show
 * a visit as it happened — a campaign landing, a referrer you did not expect.
 */
final class PageViewLog extends Panel implements ListsRows
{
    private const LIMIT = 100;

    public static function span(): int
    {
        return 3;
    }

    public function data(): array
    {
        [$start, $end] = $this->range();

        $views = [];
        $error = null;

        try {
            $views = Analytics::rows($this->logs()->query($this->logSelector()->pipe(Analytics::pageViewFilter()), $start, $end, limit: self::LIMIT));
            usort($views, static fn (array $a, array $b): int => $b['ts'] <=> $a['ts']);
        } catch (SourceException $exception) {
            $error = $exception->getMessage();
        }

        $rows = array_map(static function (array $view): array {
            $at = intdiv($view['ts'], 1000);
            $campaign = implode(' / ', array_filter([$view['utm_source'], $view['utm_medium'], $view['utm_campaign']]));

            return [
                'when' => Ui::cell(DisplayTime::at($at, 'd/m H:i:s'), ['raw' => $at, 'mono' => true]),
                'page' => Ui::cell($view['path'], ['mono' => true, 'link' => Ui::entity('path', $view['path'])]),
                'from' => Ui::cell($view['referrer'] !== '' ? $view['referrer'] : 'direct', array_filter([
                    'tone' => $view['referrer'] === '' ? 'dim' : null,
                    'sub' => $campaign !== '' ? $campaign : ($view['channel'] !== '' ? $view['channel'] : null),
                ])),
                'visitor' => Ui::cell(implode(' · ', array_filter([$view['country'], $view['device'], $view['browser']])) ?: '—', array_filter([
                    'sub' => $view['session'] !== '' ? 'visit '.substr($view['session'], 0, 8) : null,
                ])),
                '_link' => Ui::entity('path', $view['path']),
            ];
        }, array_slice($views, 0, self::LIMIT));

        return Ui::table('Recent page views', [
            Ui::col('when', 'When'),
            Ui::col('page', 'Page'),
            Ui::col('from', 'From'),
            Ui::col('visitor', 'Visitor'),
        ], $rows, [
            'subtitle' => 'Each view, newest first: the page, where the visitor came from, and what they were on',
            'span' => 3,
            'error' => $error,
            'empty' => 'No page views in this period. Analytics runs under the app\'s own service — check the service scope.',
        ]);
    }
}
