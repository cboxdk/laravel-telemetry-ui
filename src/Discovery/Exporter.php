<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Discovery;

/**
 * A kind of exporter we know how to read.
 *
 * `$detect` is the regex that says "this exporter is scraped into your
 * Prometheus"; `$identityLabels` are the labels whose values name an
 * instance, in the order we prefer them when matching one to a host or a
 * dependency address.
 */
final readonly class Exporter
{
    /**
     * @param  list<string>  $identityLabels
     * @param  list<Signal>  $signals
     */
    public function __construct(
        public string $key,
        public string $label,
        public string $detect,
        public array $identityLabels,
        public array $signals,
        /** What this exporter describes: a host, or a dependency of some kind. */
        public string $describes = 'host',
    ) {}

    /** @return list<string> */
    public function signalKeys(): array
    {
        return array_map(static fn (Signal $s): string => $s->key, $this->signals);
    }
}
