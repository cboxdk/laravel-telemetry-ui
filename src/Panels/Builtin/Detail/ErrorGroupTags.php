<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Panels\Builtin\Detail;

use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Panels\Panel;
use Cbox\TelemetryUi\Panels\Ui;

/**
 * Sentry's tag distributions: which hosts, environments, releases, services
 * and users this group's occurrences carry — the "is it just one box / one
 * release / one customer?" answer at a glance.
 */
final class ErrorGroupTags extends Panel
{
    use ScopesToGroup;

    /** occurrence field → display label */
    private const TAGS = [
        'host' => 'host',
        'environment' => 'environment',
        'release' => 'release',
        'service' => 'service',
        'user' => 'user',
    ];

    public function data(): array
    {
        $tags = [];
        $error = null;

        try {
            $occurrences = $this->groupReport()['occurrences'];

            foreach (self::TAGS as $field => $label) {
                $values = [];
                $total = 0;

                foreach ($occurrences as $occurrence) {
                    /** @var array<string, string> $detail */
                    $detail = $occurrence['detail'];
                    $value = $field === 'service' || $field === 'user'
                        ? (string) $occurrence[$field]
                        : ($detail[$field] ?? '');

                    if ($value === '') {
                        continue;
                    }

                    $values[$value] = ($values[$value] ?? 0) + 1;
                    $total++;
                }

                if ($values === []) {
                    continue;
                }

                arsort($values);

                $tags[] = [
                    'label' => $label,
                    'distinct' => count($values),
                    'top' => array_map(
                        static fn (string $value, int $count): array => [
                            'value' => $value,
                            'count' => $count,
                            'pct' => (int) round($count / max(1, $total) * 100),
                        ],
                        array_keys(array_slice($values, 0, 3, true)),
                        array_values(array_slice($values, 0, 3, true)),
                    ),
                ];
            }
        } catch (SourceException $exception) {
            $error = $exception->getMessage();
        }

        // One ranked-bars block per tag: its top three values by share.
        $parts = array_map(static fn (array $tag): array => Ui::bars(
            $tag['label'].' · '.$tag['distinct'],
            array_map(static fn (array $value): array => [
                'label' => $value['value'],
                'value' => $value['pct'],
                'display' => $value['pct'].'%',
                'sub' => $value['count'].' occurrences',
            ], $tag['top']),
        ), $tags);

        return Ui::composite('Tags', $parts, [
            'subtitle' => 'What the occurrences have in common — one host, one release, one user?',
            'span' => 2,
            'error' => $error,
            'empty' => 'No tag data on this group\'s occurrences.',
        ]);
    }
}
