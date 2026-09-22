<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Panels\Builtin\Detail;

use Cbox\TelemetryUi\Analysis\ErrorGroupReport;
use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Panels\Attributes\Param;
use Cbox\TelemetryUi\Panels\Ui;
use Cbox\TelemetryUi\Queries\Ir\TraceCondition;
use Cbox\TelemetryUi\Queries\Ir\TraceOp;
use Cbox\TelemetryUi\Support\Format;

/**
 * Scopes a card to one error group (the `?group=` on the error-detail page)
 * and fetches its shared, per-request-memoized report.
 *
 * @phpstan-import-type Report from ErrorGroupReport
 * @phpstan-import-type Link from Ui
 */
trait ScopesToGroup
{
    #[Param('group')]
    public string $group = '';

    /**
     * @return Report
     *
     * @throws SourceException
     */
    protected function groupReport(): array
    {
        if (! ErrorGroupReport::validId($this->group)) {
            return ['occurrences' => [], 'stats' => null, 'detail' => null, 'request' => null, 'suspect' => null, 'releases' => []];
        }

        return app(ErrorGroupReport::class)->for(
            $this->group,
            $this->logSelector(),
            $this->traceQuery(
                TraceCondition::token('span.browser', TraceOp::Eq, 'true'),
                TraceCondition::nil('span.exception.type'),
            ),
        );
    }

    /**
     * The group's key facts as `kv` items — shared by the deep-dive and the
     * sidebar (v1's "issue facts" strip). The host links to its detail page.
     *
     * @param  Report  $report
     * @return list<array{label: string, value: string|int|float|null, mono?: bool, link?: Link, tone?: string}>
     */
    protected function groupFacts(array $report, bool $withCount): array
    {
        $stats = $report['stats'];
        $detail = $report['detail'];

        if ($stats === null) {
            return [];
        }

        $plus = $stats['sampled'] ? '+' : '';
        $items = [];

        if ($withCount) {
            $items[] = ['label' => 'occurrences', 'value' => Format::count((float) $stats['count']).$plus, 'tone' => 'danger', 'link' => $this->occurrencesLink()];
        }

        $items[] = ['label' => 'first seen', 'value' => $stats['firstSeen']];
        $items[] = ['label' => 'last seen', 'value' => $stats['lastSeen']];
        $items[] = ['label' => 'source', 'value' => $stats['source']];

        if ($stats['users'] > 0) {
            $items[] = ['label' => 'users affected', 'value' => $stats['users'].$plus, 'link' => $this->occurrencesLink(['groupBy' => 'user.id'])];
        }

        if ($detail !== null && $detail['environment'] !== '') {
            $items[] = ['label' => 'env', 'value' => $detail['environment']];
        }

        if ($detail !== null && $detail['release'] !== '') {
            $items[] = ['label' => 'release', 'value' => $detail['release'], 'mono' => true, 'link' => $this->occurrencesLink(['groupBy' => 'deployment.id'])];
        }

        if ($detail !== null && $detail['host'] !== '') {
            $items[] = ['label' => 'host', 'value' => $detail['host'], 'link' => Ui::entity('host', $detail['host'])];
        }

        if ($detail !== null && $detail['file'] !== '') {
            $items[] = ['label' => 'at', 'value' => $detail['file'].':'.$detail['line'], 'mono' => true];
        }

        $items[] = ['label' => 'group', 'value' => $this->group, 'mono' => true];

        return $items;
    }

    /**
     * Every occurrence of this group as rows: its exception records in
     * Explore → Logs, over the report's lookback — each line opens its trace.
     *
     * @param  array<string, string>  $params
     * @return Link
     */
    protected function occurrencesLink(array $params = []): array
    {
        return Ui::explore('logs', ['exception_group='.$this->group], ['period' => '30d', ...$params]);
    }
}
