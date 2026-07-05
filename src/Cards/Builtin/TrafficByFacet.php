<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Cards\Builtin;

use Cbox\TelemetryUi\Cards\Card;
use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Queries\Results\TraceSummary;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Url;

/**
 * Traffic broken down by a span attribute facet: user, guard, user type,
 * client IP — or any custom attribute your app adds via
 * Telemetry::context()/enrichRequestsUsing(). Sampled from traces, because
 * unbounded dimensions like user ids don't belong in metric labels.
 */
final class TrafficByFacet extends Card
{
    private const FACETS = [
        'user' => ['enduser.id', 'User'],
        'guard' => ['enduser.guard', 'Guard'],
        'type' => ['enduser.type', 'User type'],
        'ip' => ['client.address', 'Client IP'],
    ];

    #[Url(as: 'facet')]
    public string $facet = 'user';

    #[Url(as: 'facet_attr')]
    public string $customAttribute = '';

    public function render(): View
    {
        [$start, $end] = $this->range();

        $attribute = $this->attribute();

        $rows = [];
        $error = null;

        if ($attribute === null) {
            $error = 'Enter a span attribute, e.g. team.id or statamic.site.';
        } else {
            try {
                $select = ' | select(span.'.$attribute.')';

                $all = $this->traces()->search(
                    '{ '.$this->traceScope('span.'.$attribute.' != nil').' }'.$select,
                    $start,
                    $end,
                    limit: 100,
                );

                $failed = $this->traces()->search(
                    '{ '.$this->traceScope('span.'.$attribute.' != nil && status = error').' }'.$select,
                    $start,
                    $end,
                    limit: 100,
                );

                $rows = $this->aggregate($attribute, $all, $failed);
            } catch (SourceException $exception) {
                $error = $exception->getMessage();
            }
        }

        /** @var view-string $view */
        $view = 'telemetry-ui::cards.traffic-by-facet';

        return view($view, [
            'rows' => array_slice($rows, 0, 100),
            'error' => $error,
            'facets' => array_map(static fn (array $facet): string => $facet[1], self::FACETS),
            'valueColumn' => $attribute !== null ? (self::FACETS[$this->facet][1] ?? $attribute) : 'Value',
        ]);
    }

    public function tracesUrl(string $value): string
    {
        $attribute = $this->attribute() ?? 'enduser.id';

        return $this->pageUrl('traces', [
            'q' => '{ '.$this->traceScope('span.'.$attribute.' = "'.addcslashes($value, '"\\').'"').' }',
        ]);
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

                foreach (array_keys($values) as $value) {
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
