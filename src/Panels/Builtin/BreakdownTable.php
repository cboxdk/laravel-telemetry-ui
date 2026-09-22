<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Panels\Builtin;

use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Panels\Panel;
use Cbox\TelemetryUi\Panels\Ui;
use Cbox\TelemetryUi\Support\Format;

/**
 * Shared shape for "name → outcome counters + duration histogram" tables
 * (commands, scheduled tasks).
 *
 * @phpstan-import-type Link from Ui
 */
abstract class BreakdownTable extends Panel
{
    /**
     * @return array{title: string, keyLabel: string, keyColumn: string, durationMetric: string, outcomes: array<string, string>}
     */
    abstract protected function spec(): array;

    public static function span(): int
    {
        return 2;
    }

    /**
     * Where a row leads (e.g. the command's entity page); null for no link.
     *
     * @return Link|null
     */
    protected function rowLink(string $name): ?array
    {
        return null;
    }

    /**
     * Where a row's failure count leads; null for no link.
     *
     * @return Link|null
     */
    protected function failedLink(string $name): ?array
    {
        return null;
    }

    /**
     * @return array<string, mixed>
     */
    public function data(): array
    {
        $spec = $this->spec();
        $p = $this->promDuration();
        $key = $spec['keyLabel'];

        $rows = [];
        $error = null;

        try {
            foreach ($spec['outcomes'] as $outcome => $metricName) {
                $samples = $this->metrics()->query(
                    $this->metric($metricName)->increase($p)->sumBy($key),
                );

                foreach ($samples as $sample) {
                    $name = $sample->labels[$key] ?? '?';

                    $rows[$name] ??= [
                        'name' => $name,
                        'outcomes' => array_fill_keys(array_keys($spec['outcomes']), 0.0),
                        'time' => 0.0, 'count' => 0.0, 'p95' => null,
                    ];

                    $rows[$name]['outcomes'][$outcome] += $sample->value;
                }
            }

            foreach ($this->metrics()->query($this->metric($spec['durationMetric'].'_sum')->increase($p)->sumBy($key)) as $sample) {
                $name = $sample->labels[$key] ?? '?';

                if (isset($rows[$name])) {
                    $rows[$name]['time'] = $sample->value;
                }
            }

            foreach ($this->metrics()->query($this->metric($spec['durationMetric'].'_count')->increase($p)->sumBy($key)) as $sample) {
                $name = $sample->labels[$key] ?? '?';

                if (isset($rows[$name])) {
                    $rows[$name]['count'] = $sample->value;
                }
            }

            foreach ($this->metrics()->query($this->metric($spec['durationMetric'].'_bucket')->quantile(0.95, $p, $key)) as $sample) {
                $name = $sample->labels[$key] ?? '?';

                if (isset($rows[$name]) && ! is_nan($sample->value)) {
                    $rows[$name]['p95'] = $sample->value;
                }
            }
        } catch (SourceException $exception) {
            $error = $exception->getMessage();
        }

        // increase() extrapolation leaves near-zero ghosts at period edges.
        $rows = array_filter($rows, static fn (array $row): bool => array_sum($row['outcomes']) >= 0.5);

        usort($rows, static fn (array $a, array $b): int => array_sum($b['outcomes']) <=> array_sum($a['outcomes']));

        $outcomes = array_keys($spec['outcomes']);

        $columns = [Ui::col('name', $spec['keyColumn'])];

        foreach ($outcomes as $outcome) {
            $columns[] = Ui::num('o_'.$outcome, ucfirst($outcome));
        }

        $columns[] = Ui::num('avg', 'AVG');
        $columns[] = Ui::num('p95', 'P95');

        $table = [];

        foreach (array_slice($rows, 0, 100) as $row) {
            $cells = ['name' => Ui::cell($row['name'], ['mono' => true])];

            foreach ($outcomes as $outcome) {
                $value = $row['outcomes'][$outcome];
                $opts = [
                    'raw' => $value,
                    'mono' => true,
                    'tone' => $outcome === 'failed' && $value > 0 ? 'danger' : null,
                ];
                $failed = $outcome === 'failed' && $value > 0 ? $this->failedLink($row['name']) : null;
                $cells['o_'.$outcome] = Ui::cell(Format::count($value), $failed !== null ? [...$opts, 'link' => $failed] : $opts);
            }

            $cells['avg'] = $row['count'] > 0
                ? Ui::cell(Format::ms($row['time'] / $row['count']), ['raw' => $row['time'] / $row['count'], 'mono' => true])
                : Ui::cell('—', ['mono' => true]);
            $cells['p95'] = $row['p95'] !== null
                ? Ui::cell(Format::ms($row['p95']), ['raw' => $row['p95'], 'mono' => true])
                : Ui::cell('—', ['mono' => true]);

            $link = $this->rowLink($row['name']);
            $table[] = $link !== null ? [...$cells, '_link' => $link] : $cells;
        }

        return Ui::table($spec['title'], $columns, $table, [
            'span' => 2,
            'error' => $error,
            'empty' => self::emptyFor($spec['title']),
        ]);
    }
}
