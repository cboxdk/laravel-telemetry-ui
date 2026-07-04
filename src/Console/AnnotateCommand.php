<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Console;

use Cbox\TelemetryUi\Support\AnnotationWriter;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * Emit an annotation marker from a deploy hook, CI step, autoscaler, migration
 * runner, feature-flag change — anything worth a vertical line on every chart.
 *
 *     php artisan telemetry-ui:annotate incident --notes="checkout 5xx spike"
 *     php artisan telemetry-ui:annotate scaling --id=web --notes="+2 workers"
 *
 * The marker must be configured under telemetry-ui.annotations.markers; it
 * lands in Loki via the telemetry emitter and the dashboard renders it.
 */
final class AnnotateCommand extends Command
{
    /** @var string */
    protected $signature = 'telemetry-ui:annotate
                            {marker : marker key (deploy, incident, scaling, migration, feature, …)}
                            {--id= : identifier shown on the marker}
                            {--notes= : free-form note}';

    /** @var string */
    protected $description = 'Emit an annotation marker (deploy, incident, scaling, …) into the telemetry store.';

    public function handle(AnnotationWriter $writer): int
    {
        $marker = $this->argument('marker');
        $marker = is_string($marker) ? $marker : '';
        $id = $this->option('id');
        $notes = $this->option('notes');

        try {
            $emitted = $writer->write(
                $marker,
                is_string($id) && $id !== '' ? $id : null,
                is_string($notes) && $notes !== '' ? $notes : null,
            );
        } catch (InvalidArgumentException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($emitted) {
            $this->components->info("Annotation [{$marker}] emitted.");
        } else {
            $this->components->warn("Telemetry is disabled; annotation [{$marker}] was not emitted.");
        }

        return self::SUCCESS;
    }
}
