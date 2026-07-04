<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Support;

use Cbox\Telemetry\TelemetryManager;
use Illuminate\Contracts\Config\Repository as Config;
use InvalidArgumentException;

/**
 * Writes annotation markers into the telemetry pipeline (→ Loki) by reusing
 * cboxdk/laravel-telemetry's event emitter — the same path `telemetry:deploy`
 * uses. No local state: the annotation lives in the same store the dashboard
 * already reads from, so the marker round-trips through the existing
 * annotation reader. The UI hard-requires the emitter, so the write path is
 * always there (and the dashboard dogfoods its own stack).
 */
final readonly class AnnotationWriter
{
    public function __construct(
        private TelemetryManager $telemetry,
        private Config $config,
    ) {}

    /**
     * Emit an annotation for a configured marker (deploy, incident, scaling,
     * …). Returns whether it was actually emitted — telemetry may be disabled.
     *
     * @param  array<string, scalar|null>  $extra  extra OTLP attributes
     */
    public function write(string $marker, ?string $id = null, ?string $notes = null, array $extra = []): bool
    {
        /** @var array<string, mixed>|null $config */
        $config = $this->config->get('telemetry-ui.annotations.markers.'.$marker);

        if (! is_array($config) || ! is_string($config['event'] ?? null) || $config['event'] === '') {
            throw new InvalidArgumentException(
                "Unknown annotation marker [{$marker}]. Configure it under telemetry-ui.annotations.markers.",
            );
        }

        $attributes = $extra;

        // Loki labels are snake_case; the OTLP attribute is the dotted form
        // (deployment_id -> deployment.id) that the emitter flattens back.
        if (is_string($config['id_label'] ?? null) && $id !== null && $id !== '') {
            $attributes[str_replace('_', '.', $config['id_label'])] = $id;
        }

        if (is_string($config['notes_label'] ?? null) && $notes !== null && $notes !== '') {
            $attributes[str_replace('_', '.', $config['notes_label'])] = $notes;
        }

        if (! $this->telemetry->enabled()) {
            return false; // fail-open: nothing to emit when telemetry is off.
        }

        $this->telemetry->event($config['event'], $attributes);
        $this->telemetry->flush();

        return true;
    }
}
