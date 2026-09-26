<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Console;

use Cbox\Telemetry\TelemetryManager;
use Cbox\TelemetryUi\Connectors\ConnectionManager;
use Cbox\TelemetryUi\Contracts\EnumeratesMetricNames;
use Cbox\TelemetryUi\Queries\Ir\MetricQuery;
use Cbox\TelemetryUi\Support\ScopeLabels;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository as Config;
use Throwable;

/**
 * Probe each configured connection with its cheapest read so an operator can
 * confirm URL, auth and tenancy are right before trusting the dashboard —
 * the "does my config actually reach the backends?" preflight.
 */
final class CheckCommand extends Command
{
    /** @var string */
    protected $signature = 'telemetry-ui:check'
        .' {--connection=* : Only probe these named connections}'
        .' {--signals : Also check that each configured context signal resolves}';

    /** @var string */
    protected $description = 'Probe each configured Telemetry UI connection and report reachability + auth.';

    public function handle(ConnectionManager $manager, Config $config): int
    {
        /** @var list<string> $only */
        $only = (array) $this->option('connection');

        $probes = [
            'metrics' => fn (): string => $this->probeMetrics($manager),
            'traces' => fn (): string => $this->probeTraces($manager),
            'logs' => fn (): string => $this->probeLogs($manager),
            'issues' => fn (): string => $this->probeIssues($manager),
        ];

        $rows = [];
        $failed = false;
        $probed = 0;

        foreach ($probes as $name => $probe) {
            if ($only !== [] && ! in_array($name, $only, true)) {
                continue;
            }

            $driver = $this->driverFor($config, $name);

            if ($driver === null) {
                $rows[] = [$name, '—', '<fg=gray>not configured</>', ''];

                continue;
            }

            $probed++;

            try {
                $rows[] = [$name, $driver, '<fg=green>OK</>', $probe()];
            } catch (Throwable $exception) {
                $failed = true;
                $rows[] = [$name, $driver, '<fg=red>FAIL</>', $this->shorten($exception->getMessage())];
            }
        }

        $this->newLine();
        $this->table(['Connection', 'Driver', 'Status', 'Detail'], $rows);

        $this->reportEmitter();

        if ((bool) $this->option('signals')) {
            $this->reportSignals($manager, $config);
        }

        if ($failed) {
            $this->error('One or more connections failed. Check url, token/basic_auth and tenant in config/telemetry-ui.php.');

            return self::FAILURE;
        }

        if ($probed === 0) {
            $this->warn('No connections configured to probe.');

            return self::SUCCESS;
        }

        $this->info('All configured connections are reachable.');

        return self::SUCCESS;
    }

    /**
     * Which `context.signals` actually produce data.
     *
     * A signal whose metric is absent is skipped silently, which is right
     * for an optional exporter and terrible for a typo: the correlation
     * panel simply shows one fewer tile and nobody is told why. This makes
     * the silence visible, so a name that matches nothing is a line in a
     * table instead of a feature that quietly does nothing.
     */
    private function reportSignals(ConnectionManager $manager, Config $config): void
    {
        $signals = $config->get('telemetry-ui.context.signals');

        if (! is_array($signals) || $signals === []) {
            $this->newLine();
            $this->warn('No context signals configured.');

            return;
        }

        $rows = [];
        $resolved = 0;

        foreach ($signals as $signal) {
            if (! is_array($signal)) {
                continue;
            }

            $label = is_string($signal['label'] ?? null) ? $signal['label'] : '(unlabelled)';
            $query = is_string($signal['query'] ?? null) ? $signal['query'] : '';

            if ($query === '') {
                $rows[] = [$label, '<fg=red>no query</>', ''];

                continue;
            }

            // Unscoped on purpose: this asks whether the metric exists at
            // all, not whether one service reported it in some window.
            $promql = str_replace(['{{scope}}', '{scope},', '{scope}'], ['', '', ''], $query);

            try {
                $samples = $manager->metrics()->query(new MetricQuery('', raw: $promql));

                if ($samples === []) {
                    $rows[] = [$label, '<fg=yellow>no data</>', $this->shorten($promql)];

                    continue;
                }

                $resolved++;
                $rows[] = [$label, '<fg=green>OK</>', count($samples).' series'];
            } catch (Throwable $exception) {
                $rows[] = [$label, '<fg=red>FAIL</>', $this->shorten($exception->getMessage())];
            }
        }

        $this->newLine();
        $this->line('<options=bold>Context signals</>');
        $this->table(['Signal', 'Status', 'Detail'], $rows);

        if ($resolved < count($rows)) {
            $this->warn(
                'A signal with no data contributes nothing to the correlation panel. '
                .'Either its exporter is not running, or the metric name does not match what your pipeline emits.'
            );
        }
    }

    /**
     * The annotation write path (`telemetry-ui:annotate`, `scan-versions`) runs
     * through cboxdk/laravel-telemetry's emitter. Report whether it will emit —
     * informational only, so it doesn't affect the connection exit code.
     */
    private function reportEmitter(): void
    {
        try {
            $enabled = $this->laravel->make(TelemetryManager::class)->enabled();
        } catch (Throwable) {
            return;
        }

        $this->line($enabled
            ? '<fg=green>✓</> Telemetry emitter enabled — annotations will write to the logs backend.'
            : '<fg=yellow>•</> Telemetry emitter disabled — annotation markers will be skipped until it is enabled.');
    }

    private function driverFor(Config $config, string $name): ?string
    {
        $connection = $config->get("telemetry-ui.connections.{$name}");
        $driver = is_array($connection) ? ($connection['driver'] ?? null) : null;

        return is_string($driver) && $driver !== '' ? $driver : null;
    }

    private function probeMetrics(ConnectionManager $manager): string
    {
        $metrics = $manager->metrics();

        // The series index, the same way the traces and logs probes ask for
        // tag and label values. It is metadata rather than a synthetic query,
        // so it works on any backend serving the read API, and it answers
        // something worth printing: how many metric names are actually there.
        if ($metrics instanceof EnumeratesMetricNames) {
            return count($metrics->metricNamesMatching(['.+'])).' metric name(s)';
        }

        // A bare scalar for drivers without a series index. NOT vector(1):
        // that is a PromQL *function*, and a backend can implement the query
        // API without implementing it — telemetryd answers `1` and refuses
        // `vector(1)`, which failed this check on a connection that was
        // working perfectly.
        return count($metrics->query(MetricQuery::raw('1'))).' sample(s) returned';
    }

    private function probeTraces(ConnectionManager $manager): string
    {
        $services = $manager->traces()->tagValues('service.name');

        return count($services).' service.name value(s)';
    }

    private function probeLogs(ConnectionManager $manager): string
    {
        $values = $manager->logs()->labelValues(ScopeLabels::logs('service'));

        return count($values).' service_name label value(s)';
    }

    private function probeIssues(ConnectionManager $manager): string
    {
        $issues = $manager->issues()->issues('open', null, 1);

        return count($issues).' open issue(s) (probed a page of 1)';
    }

    private function shorten(string $message): string
    {
        $message = trim(preg_replace('/\s+/', ' ', $message) ?? $message);

        return mb_strlen($message) > 140 ? mb_substr($message, 0, 139).'…' : $message;
    }
}
