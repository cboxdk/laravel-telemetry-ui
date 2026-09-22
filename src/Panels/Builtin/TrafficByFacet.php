<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Panels\Builtin;

use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Panels\Attributes\Param;
use Cbox\TelemetryUi\Panels\Panel;
use Cbox\TelemetryUi\Panels\Ui;
use Cbox\TelemetryUi\Queries\Ir\TraceCondition;
use Cbox\TelemetryUi\Queries\Ir\TraceOp;
use Cbox\TelemetryUi\Queries\Results\TraceSummary;
use Cbox\TelemetryUi\TelemetryUiManager;

/**
 * Traffic broken down by a span attribute facet: user, guard, user type,
 * client IP — or any custom attribute your app adds via
 * Telemetry::context()/enrichRequestsUsing(). Sampled from traces, because
 * unbounded dimensions like user ids don't belong in metric labels.
 *
 * @phpstan-import-type Link from Ui
 */
final class TrafficByFacet extends Panel
{
    private const FACETS = [
        'user' => ['user.id', 'User'],
        'guard' => ['user.guard', 'Guard'],
        'type' => ['user.type', 'User type'],
        'ip' => ['client.address', 'Client IP'],
    ];

    #[Param('facet')]
    public string $facet = 'user';

    #[Param('facet_attr')]
    public string $customAttribute = '';

    public static function span(): int
    {
        return 2;
    }

    public function data(): array
    {
        [$start, $end] = $this->range();

        $attribute = $this->attribute();

        $rows = [];
        $error = null;

        if ($attribute === null) {
            $error = 'Enter a span attribute, e.g. team.id or statamic.site.';
        } else {
            try {
                $all = $this->traces()->search(
                    $this->traceQuery(TraceCondition::nil('span.'.$attribute))->select('span.'.$attribute),
                    $start,
                    $end,
                    limit: 100,
                );

                $failed = $this->traces()->search(
                    $this->traceQuery(
                        TraceCondition::nil('span.'.$attribute),
                        TraceCondition::token('status', TraceOp::Eq, 'error'),
                    )->select('span.'.$attribute),
                    $start,
                    $end,
                    limit: 100,
                );

                $rows = $this->aggregate($attribute, $all, $failed);
            } catch (SourceException $exception) {
                $error = $exception->getMessage();
            }
        }

        $controls = [Ui::select('facet', 'Facet', $this->facet, [
            ...array_map(
                static fn (string $key, array $facet): array => ['value' => $key, 'label' => $facet[1]],
                array_keys(self::FACETS),
                array_values(self::FACETS),
            ),
            ['value' => 'custom', 'label' => 'Custom attribute…'],
        ])];

        if ($this->facet === 'custom') {
            $controls[] = Ui::search('facet_attr', 'Attribute', $this->customAttribute, 'span attribute, e.g. team.id');
        }

        $valueColumn = $attribute !== null ? (self::FACETS[$this->facet][1] ?? $attribute) : 'Value';

        $columns = [
            Ui::col('value', $valueColumn),
            Ui::num('traces', 'Traces (sampled)'),
            Ui::num('errors', 'Errors (sampled)'),
            Ui::col('lastAction', 'Last action'),
            Ui::num('lastSeen', 'Last seen'),
        ];

        // A declared dimension (user.id, client.address, …) lets the SPA offer
        // filter/group on the value; an ad-hoc attribute just drills.
        $declared = $attribute !== null && app(TelemetryUiManager::class)->dimensions()->get($attribute) !== null;

        $cells = array_map(function (array $row) use ($attribute, $declared): array {
            $link = $this->tracesLink($row['value']);

            $value = ['mono' => true, 'link' => $link];

            if ($declared && $attribute !== null) {
                $value['dim'] = ['key' => $attribute, 'value' => $row['value']];
            }

            return [
                'value' => Ui::cell($row['value'], $value),
                'traces' => Ui::cell($row['traces'], ['raw' => $row['traces']]),
                'errors' => Ui::cell($row['errors'], ['raw' => $row['errors'], 'tone' => $row['errors'] > 0 ? 'danger' : null]),
                'lastAction' => $row['lastAction'] !== '' ? Ui::cell($row['lastAction'], ['link' => Ui::rootOperation($row['lastAction'])]) : Ui::cell('—'),
                // A time alone is ambiguous across a multi-day period.
                'lastSeen' => Ui::cell(
                    $row['lastSeen']->format($row['lastSeen']->format('Y-m-d') === date('Y-m-d') ? 'H:i:s' : 'M j H:i'),
                    ['raw' => $row['lastSeen']->getTimestamp() * 1000, 'mono' => true],
                ),
                '_link' => $link,
            ];
        }, array_slice($rows, 0, 100));

        return Ui::table('Traffic by '.($attribute !== null ? lcfirst($valueColumn) : 'attribute'), $columns, $cells, array_filter([
            'subtitle' => 'Requests grouped by a span attribute (user, guard, IP or custom), sampled from traces',
            'controls' => $controls,
            'error' => $error,
            'empty' => 'No traces carrying this attribute in the period.',
            'note' => 'Sampled from the most recent 100 matching traces per column — trends, not exact counts.',
        ], static fn (mixed $v): bool => $v !== null));
    }

    /**
     * Explore → Requests filtered to one facet value.
     *
     * @return Link
     */
    private function tracesLink(string $value): array
    {
        return Ui::explore('requests', [($this->attribute() ?? 'user.id').'='.$value]);
    }

    /**
     * The span attribute behind the selected facet; null when a custom
     * facet is selected but no valid attribute has been entered yet.
     */
    private function attribute(): ?string
    {
        if (isset(self::FACETS[$this->facet])) {
            return self::FACETS[$this->facet][0];
        }

        $custom = trim($this->customAttribute);

        return preg_match('/^[a-zA-Z0-9_.\-]+$/', $custom) === 1 ? $custom : null;
    }

    /**
     * @param  list<TraceSummary>  $all
     * @param  list<TraceSummary>  $failed
     * @return list<array{value: string, traces: int, errors: int, lastSeen: \DateTimeImmutable, lastAction: string}>
     */
    private function aggregate(string $attribute, array $all, array $failed): array
    {
        $rows = [];

        foreach (['traces' => $all, 'errors' => $failed] as $bucket => $results) {
            foreach ($results as $summary) {
                $values = [];

                foreach ($summary->matchedSpans as $span) {
                    $value = $span->attributes[$attribute] ?? null;

                    if (is_scalar($value) && (string) $value !== '') {
                        $values[(string) $value] = true;
                    }
                }

                foreach (array_keys($values) as $key) {
                    // PHP turns numeric-string keys (user id 42) into ints.
                    $value = (string) $key;

                    $rows[$value] ??= [
                        'value' => $value,
                        'traces' => 0,
                        'errors' => 0,
                        'lastSeen' => $summary->startedAt,
                        'lastAction' => $summary->rootTraceName,
                    ];

                    $rows[$value][$bucket]++;

                    if ($bucket === 'traces' && $summary->startedAt > $rows[$value]['lastSeen']) {
                        $rows[$value]['lastSeen'] = $summary->startedAt;
                        $rows[$value]['lastAction'] = $summary->rootTraceName;
                    }
                }
            }
        }

        usort($rows, static fn (array $a, array $b): int => [$b['traces'], $b['lastSeen']] <=> [$a['traces'], $a['lastSeen']]);

        return $rows;
    }
}
