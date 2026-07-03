<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Console;

use Cbox\TelemetryUi\Connectors\ConnectionManager;
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
    protected $signature = 'telemetry-ui:check {--connection=* : Only probe these named connections}';

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

    private function driverFor(Config $config, string $name): ?string
    {
        $connection = $config->get("telemetry-ui.connections.{$name}");
        $driver = is_array($connection) ? ($connection['driver'] ?? null) : null;

        return is_string($driver) && $driver !== '' ? $driver : null;
    }

    private function probeMetrics(ConnectionManager $manager): string
    {
        // vector(1) is a trivial instant query every Prometheus/Mimir answers.
        $samples = $manager->metrics()->query('vector(1)');

        return count($samples).' sample(s) returned';
    }

    private function probeTraces(ConnectionManager $manager): string
    {
        $services = $manager->traces()->tagValues('service.name');

        return count($services).' service.name value(s)';
    }

    private function probeLogs(ConnectionManager $manager): string
    {
        $values = $manager->logs()->labelValues('service_name');

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
