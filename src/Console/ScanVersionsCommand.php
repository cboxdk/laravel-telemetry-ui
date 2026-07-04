<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Console;

use Cbox\TelemetryUi\Analysis\VersionScanner;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository as Config;

/**
 * Detects newly-seen application versions and auto-annotates them, so a deploy
 * that never ran `telemetry-ui:annotate deploy` still shows on the charts.
 * Schedule it every few minutes:
 *
 *     $schedule->command('telemetry-ui:scan-versions')->everyFiveMinutes();
 */
final class ScanVersionsCommand extends Command
{
    /** @var string */
    protected $signature = 'telemetry-ui:scan-versions';

    /** @var string */
    protected $description = 'Auto-annotate application versions first seen in production.';

    public function handle(VersionScanner $scanner, Config $config): int
    {
        if (! (bool) $config->get('telemetry-ui.annotations.auto_version.enabled', false)) {
            $this->components->warn('Auto-version annotations are off (telemetry-ui.annotations.auto_version.enabled).');

            return self::SUCCESS;
        }

        $result = $scanner->scan();

        foreach ($result['emitted'] as $version) {
            $this->components->info("Annotated newly-seen version: {$version}");
        }

        if ($result['emitted'] === []) {
            $this->components->info("No new versions ({$result['live']} live).");
        }

        return self::SUCCESS;
    }
}
