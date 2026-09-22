<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Panels\Builtin;

use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Panels\Panel;
use Cbox\TelemetryUi\Panels\Ui;
use Cbox\TelemetryUi\Support\Format;

/**
 * Hits, misses and writes per cache store with each store's hit ratio — the
 * overview's single ratio can hide one cold store behind a hot one.
 */
final class CacheByStore extends Panel
{
    public static function span(): int
    {
        return 2;
    }

    public function data(): array
    {
        $stores = [];
        $error = null;

        try {
            $samples = $this->metrics()->query(
                $this->metric('cache_operations_total')->increase($this->promDuration())->sumBy('store', 'operation'),
            );

            foreach ($samples as $sample) {
                $store = $sample->labels['store'] ?? '?';
                $operation = $sample->labels['operation'] ?? '?';
                $stores[$store] ??= ['hit' => 0.0, 'miss' => 0.0, 'write' => 0.0];

                if (isset($stores[$store][$operation])) {
                    $stores[$store][$operation] += $sample->value;
                }
            }
        } catch (SourceException $exception) {
            $error = $exception->getMessage();
        }

        // increase() extrapolation leaves near-zero ghosts at period edges.
        $stores = array_filter($stores, static fn (array $s): bool => array_sum($s) >= 0.5);
        uasort($stores, static fn (array $a, array $b): int => array_sum($b) <=> array_sum($a));

        $rows = [];

        foreach ($stores as $store => $ops) {
            $reads = $ops['hit'] + $ops['miss'];
            $ratio = $reads > 0.0 ? $ops['hit'] / $reads : null;

            $rows[] = [
                'store' => Ui::cell((string) $store, ['mono' => true]),
                'hit' => Ui::cell(Format::count($ops['hit']), ['raw' => $ops['hit'], 'mono' => true]),
                'miss' => Ui::cell(Format::count($ops['miss']), ['raw' => $ops['miss'], 'mono' => true, 'tone' => $ops['miss'] > $ops['hit'] ? 'warn' : null]),
                'write' => Ui::cell(Format::count($ops['write']), ['raw' => $ops['write'], 'mono' => true]),
                'ratio' => $ratio !== null
                    ? Ui::cell(Format::percent($ratio), ['raw' => $ratio, 'mono' => true, 'bar' => $ratio, 'tone' => $ratio < 0.5 ? 'warn' : 'ok'])
                    : Ui::cell('—', ['raw' => -1, 'mono' => true]),
            ];
        }

        return Ui::table('Cache by store', [
            Ui::col('store', 'Store'),
            Ui::num('hit', 'Hits'),
            Ui::num('miss', 'Misses'),
            Ui::num('write', 'Writes'),
            Ui::num('ratio', 'Hit ratio'),
        ], $rows, [
            'subtitle' => 'Per store — a low ratio on one store hides behind a hot one in the overall number',
            'span' => 2,
            'error' => $error,
            'empty' => self::emptyFor('Cache operations'),
        ]);
    }
}
