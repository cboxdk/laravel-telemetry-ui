<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Panels\Builtin;

use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Panels\Panel;
use Cbox\TelemetryUi\Panels\Ui;
use Cbox\TelemetryUi\Support\Format;
use Cbox\TelemetryUi\Support\ScopeLabels;

/**
 * Connection setup time, from each of our machines to each upstream — the
 * one view that finds a host nobody provisioned correctly.
 *
 * Averaged across the fleet, a slow handshake to an upstream looks like a
 * slow upstream, and there is nothing to do about a slow upstream. Split
 * by the machine that made the call, the same number says something
 * entirely different: nine hosts reach the endpoint in 4ms and the tenth
 * takes 80, which is not the upstream at all. That is a box in the wrong
 * region, on the wrong network, resolving through the wrong DNS, or
 * missing the peering everyone else has.
 *
 * Only calls that actually opened a connection are counted. A reused one
 * reports a setup of zero, and including those would move every pair
 * toward zero in proportion to how well its pooling happens to be
 * working — hiding exactly the hosts that are worst off.
 */
final class OutgoingConnectionPairs extends Panel
{
    /** Below this, a difference is noise rather than a finding. */
    private const OUTLIER_FACTOR = 3.0;

    public static function span(): int
    {
        return 2;
    }

    /**
     * @return array<string, mixed>
     */
    public function data(): array
    {
        $p = $this->promDuration();
        $hostLabel = ScopeLabels::metrics('host');

        $bucket = $this->metric('http_client_connection_duration_seconds_bucket');
        $sum = $this->metric('http_client_connection_duration_seconds_sum');
        $count = $this->metric('http_client_connection_duration_seconds_count');

        /** @var array<string, array{from: string, to: string, p95: float|null, time: float, connects: float}> $pairs */
        $pairs = [];
        $error = null;

        $key = static fn (string $from, string $to): string => $from."\0".$to;

        try {
            foreach ($this->metrics()->query($count->increase($p)->sumBy($hostLabel, 'server_address')) as $sample) {
                $from = (string) ($sample->labels[$hostLabel] ?? '');
                $to = (string) ($sample->labels['server_address'] ?? '');

                if ($from === '' || $to === '') {
                    continue;
                }

                $pairs[$key($from, $to)] = ['from' => $from, 'to' => $to, 'p95' => null, 'time' => 0.0, 'connects' => $sample->value];
            }

            foreach ($this->metrics()->query($sum->increase($p)->sumBy($hostLabel, 'server_address')) as $sample) {
                $id = $key((string) ($sample->labels[$hostLabel] ?? ''), (string) ($sample->labels['server_address'] ?? ''));

                if (isset($pairs[$id])) {
                    $pairs[$id]['time'] = $sample->value * 1000;
                }
            }

            foreach ($this->metrics()->query($bucket->quantile(0.95, $p, $hostLabel, 'server_address')) as $sample) {
                $id = $key((string) ($sample->labels[$hostLabel] ?? ''), (string) ($sample->labels['server_address'] ?? ''));

                if (isset($pairs[$id]) && ! is_nan($sample->value)) {
                    $pairs[$id]['p95'] = $sample->value * 1000;
                }
            }
        } catch (SourceException $exception) {
            $error = $exception->getMessage();
        }

        $rows = array_values($pairs);
        $typical = self::typicalPerUpstream($rows);

        // Worst first: the reason to open this panel is to find the bad
        // pair, not to read the good ones.
        usort($rows, static fn (array $a, array $b): int => ($b['p95'] ?? 0.0) <=> ($a['p95'] ?? 0.0));

        $table = array_map(static function (array $row) use ($typical): array {
            $avg = $row['connects'] > 0 ? $row['time'] / $row['connects'] : null;
            $median = $typical[$row['to']] ?? null;

            // An outlier is only meaningful against its OWN upstream:
            // 40ms to a service on another continent is expected, and
            // 40ms to one in the same rack is the finding.
            $outlier = $median !== null
                && $median > 0.0
                && $row['p95'] !== null
                && $row['p95'] > $median * self::OUTLIER_FACTOR;

            return [
                'from' => Ui::cell($row['from'], ['mono' => true, 'dim' => ['key' => 'host.name', 'value' => $row['from']]]),
                'to' => Ui::cell($row['to'], [
                    'mono' => true,
                    'link' => Ui::entity('outgoing', $row['to']),
                    'dim' => ['key' => 'server.address', 'value' => $row['to']],
                ]),
                'connects' => Ui::cell(Format::count($row['connects']), ['raw' => $row['connects']]),
                'avg' => $avg === null ? Ui::cell('—') : Ui::cell(Format::ms($avg), ['raw' => $avg]),
                'p95' => $row['p95'] === null
                    ? Ui::cell('—')
                    : Ui::cell(Format::ms($row['p95']), ['raw' => $row['p95'], 'tone' => $outlier ? 'danger' : null]),
                'vs' => $median === null || $median <= 0.0 || $row['p95'] === null
                    ? Ui::cell('—')
                    // One decimal, not Format::count: it rounds, and the
                    // difference between 3.4x and 3x is the difference
                    // between a finding and a rounding.
                    : Ui::cell(number_format($row['p95'] / $median, 1).'×', [
                        'raw' => $row['p95'] / $median,
                        'tone' => $outlier ? 'danger' : 'dim',
                    ]),
            ];
        }, array_slice($rows, 0, 100));

        return Ui::table('Connection setup, host → upstream', [
            Ui::col('from', 'From host'),
            Ui::col('to', 'To upstream'),
            Ui::num('connects', 'Connections'),
            Ui::num('avg', 'AVG'),
            Ui::num('p95', 'P95'),
            Ui::num('vs', 'vs. peers'),
        ], $table, array_filter([
            'error' => $error,
            'subtitle' => 'DNS + TCP + TLS only, and only for calls that opened a connection. '
                .'"vs. peers" compares each host against the median for the same upstream — a host '
                .'several times its peers is a provisioning problem, not a slow service.',
            'empty' => 'No connections were opened in this period. Either everything was pooled, or nothing called out.',
        ], static fn ($v): bool => $v !== null));
    }

    /**
     * The median p95 per upstream, which is what "everybody else" means.
     *
     * A mean would be dragged by the very outlier being looked for, and
     * on a fleet of three that is enough to hide it.
     *
     * @param  list<array{from: string, to: string, p95: float|null, time: float, connects: float}>  $rows
     * @return array<string, float>
     */
    private static function typicalPerUpstream(array $rows): array
    {
        $byUpstream = [];

        foreach ($rows as $row) {
            if ($row['p95'] !== null) {
                $byUpstream[$row['to']][] = $row['p95'];
            }
        }

        $median = [];

        foreach ($byUpstream as $upstream => $values) {
            // One host is not a peer group; there is nothing to compare
            // it against and saying "1×" would imply there was.
            if (count($values) < 2) {
                continue;
            }

            sort($values);
            $middle = intdiv(count($values), 2);

            $median[$upstream] = count($values) % 2 === 1
                ? $values[$middle]
                : ($values[$middle - 1] + $values[$middle]) / 2;
        }

        return $median;
    }
}
