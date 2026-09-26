<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Console;

use Cbox\TelemetryUi\Discovery\Catalogue;
use Cbox\TelemetryUi\Discovery\DiscoveredTarget;
use Cbox\TelemetryUi\Discovery\Discoverer;
use Illuminate\Console\Command;

/**
 * Find the infrastructure behind this application and show what was found.
 *
 * The point of printing it is that a wrong match is otherwise silent: you
 * would get one fewer tile on a trace and no explanation. Here you can see
 * which exporter was tied to which host, on what evidence, and what could
 * not be placed at all.
 */
final class DiscoverCommand extends Command
{
    public const NAME = 'telemetry-ui:discover';

    protected $signature = self::NAME.' {--fresh : Ignore the cached map and probe again}';

    protected $description = 'Discover which infrastructure exporters are scraped, and which host or dependency each one describes.';

    public function handle(Discoverer $discoverer): int
    {
        $map = (bool) $this->option('fresh') ? $discoverer->discover() : $discoverer->map();

        if ($map->exportersPresent === []) {
            $this->warn('No known infrastructure exporters found in the metrics store.');
            $this->line('  <fg=gray>Install node_exporter, redis_exporter, mysqld_exporter … and scrape them; nothing needs configuring here.</>');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->line('<options=bold>Exporters present</>  '.implode(', ', $map->exportersPresent));

        if ($map->targets !== []) {
            $this->newLine();
            $this->table(
                ['Target', 'Exporter', 'Instance', 'Matched on', 'Signals'],
                array_map(static fn (DiscoveredTarget $t): array => [
                    $t->name,
                    $t->exporter,
                    $t->instance,
                    $t->matchedOn,
                    count($t->signals).' of '.(count($t->signals) + count($t->absent)),
                ], $map->targets),
            );
        }

        if ($map->targets === [] && $map->unmatchedHosts === []) {
            $this->newLine();
            $this->warn('No host or dependency names could be read from your traces.');
            $this->line('  <fg=gray>Exporters were found, but there is nothing to tie them to. Check that the traces backend can enumerate tag values over the discovery lookback.</>');
        }

        $this->reportGaps($map->unmatchedInstances, $map->unmatchedHosts);
        $this->reportAbsentSignals($map->targets);

        return self::SUCCESS;
    }

    /**
     * @param  list<array{exporter: string, instance: string}>  $instances
     * @param  list<string>  $hosts
     */
    private function reportGaps(array $instances, array $hosts): void
    {
        if ($instances !== []) {
            $this->newLine();
            $this->line('<options=bold>Exporter instances nothing claims</>');

            foreach (array_slice($instances, 0, 10) as $entry) {
                $this->line('  <fg=yellow>'.$entry['exporter'].'</> '.$entry['instance']);
            }

            $this->line('  <fg=gray>Either this app does not talk to them, or your scrape config names them differently from the hosts in your traces.</>');
        }

        if ($hosts !== []) {
            $this->newLine();
            $this->line('<options=bold>Hosts with no exporter behind them</>');
            $this->line('  '.implode(', ', array_slice($hosts, 0, 15)));
            $this->line('  <fg=gray>Install an exporter there and it will be picked up on the next run.</>');
        }
    }

    /**
     * A signal that returned nothing is the failure mode worth surfacing:
     * absent for a good reason (no replica, no cgroup) or because the name
     * is wrong, and only a human can tell which.
     *
     * @param  list<DiscoveredTarget>  $targets
     */
    private function reportAbsentSignals(array $targets): void
    {
        $absent = [];

        foreach ($targets as $target) {
            foreach ($target->absent as $key) {
                $exporter = Catalogue::find($target->exporter);

                if ($exporter === null) {
                    continue;
                }

                $signal = null;

                foreach ($exporter->signals as $candidate) {
                    if ($candidate->key === $key) {
                        $signal = $candidate;
                    }
                }

                if ($signal !== null && ! $signal->conditional) {
                    $absent[$target->exporter.' · '.$signal->label] = true;
                }
            }
        }

        if ($absent === []) {
            return;
        }

        $this->newLine();
        $this->warn('Signals that returned no data (conditional ones are not listed):');

        foreach (array_keys($absent) as $line) {
            $this->line('  '.$line);
        }
    }
}
