<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Discovery;

use Cbox\TelemetryUi\Connectors\ConnectionManager;
use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Queries\Ir\MetricQuery;
use Cbox\TelemetryUi\Support\SchemaDetector;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Config\Repository as Config;

/**
 * Works out what infrastructure is behind this application, by asking the
 * metrics store rather than being told.
 *
 * Adding an exporter is an infrastructure job: someone installs
 * node_exporter on the box, or points redis_exporter at the cache, and
 * Prometheus scrapes it. Nothing should have to be configured here for that
 * to show up — so this looks for what is there, matches it to the hosts and
 * dependencies the traces already name, and remembers the result.
 *
 * Matching is on evidence only. An exporter instance is tied to a host when
 * one of its identifying labels actually contains that host's name; when
 * nothing does, it goes in the unmatched list rather than being guessed
 * into place. A confident wrong match would have an incident report
 * describing the memory of a machine nobody touched.
 */
final readonly class Discoverer
{
    private const CACHE_KEY = 'telemetry-ui:discovery:map';

    public function __construct(
        private ConnectionManager $connections,
        private SchemaDetector $detector,
        private CacheFactory $cache,
        private Config $config,
    ) {}

    /**
     * The cached map, discovering it first if the cache is cold.
     */
    public function map(): InfrastructureMap
    {
        $cached = $this->cache->store()->get(self::CACHE_KEY);

        if (is_array($cached)) {
            return InfrastructureMap::fromArray($cached);
        }

        return $this->discover();
    }

    /**
     * Probe everything and replace the cached map.
     *
     * Costs one metric-name lookup, one label lookup per exporter present,
     * two tag lookups for the hosts and dependency addresses, and one query
     * per signal per matched target. Run it on a schedule — infrastructure
     * changes at deploy speed, not request speed.
     */
    public function discover(): InfrastructureMap
    {
        $exporters = $this->present();
        $names = $this->knownNames();

        $targets = [];
        $unmatchedInstances = [];
        $claimed = [];

        foreach ($exporters as $exporter) {
            foreach ($this->instances($exporter) as $label => $values) {
                foreach ($values as $value) {
                    $name = $this->match($value, $names);

                    if ($name === null) {
                        $unmatchedInstances[] = ['exporter' => $exporter->key, 'instance' => $value];

                        continue;
                    }

                    $claimed[$name] = true;
                    $selector = $label.'="'.str_replace('"', '', $value).'"';
                    [$resolved, $absent] = $this->probe($exporter, $selector);

                    $targets[] = new DiscoveredTarget(
                        name: $name,
                        exporter: $exporter->key,
                        instance: $value,
                        matchedOn: $label,
                        selector: $selector,
                        signals: $resolved,
                        absent: $absent,
                    );
                }
            }
        }

        $map = new InfrastructureMap(
            targets: $targets,
            exportersPresent: array_map(static fn (Exporter $e): string => $e->key, $exporters),
            unmatchedInstances: $unmatchedInstances,
            unmatchedHosts: array_values(array_filter($names, static fn (string $n): bool => ! isset($claimed[$n]))),
            discoveredAt: time(),
        );

        $this->cache->store()->put(self::CACHE_KEY, $map->toArray(), $this->ttl());

        return $map;
    }

    public function forget(): void
    {
        $this->cache->store()->forget(self::CACHE_KEY);
    }

    /**
     * Which catalogue exporters are actually scraped into this store. One
     * batched lookup for all of them.
     *
     * @return list<Exporter>
     */
    private function present(): array
    {
        $catalogue = Catalogue::all();
        $patterns = array_map(static fn (Exporter $e): string => $e->detect, $catalogue);

        try {
            $found = $this->detector->detect($patterns);
        } catch (SourceException) {
            return [];
        }

        return array_values(array_filter(
            $catalogue,
            static fn (Exporter $e): bool => ($found[$e->detect] ?? false) === true,
        ));
    }

    /**
     * The names this application actually uses: the hosts its spans ran on,
     * and the addresses its outbound calls went to. Anything an exporter
     * describes that is not one of these is infrastructure this app does
     * not talk to.
     *
     * @return list<string>
     */
    private function knownNames(): array
    {
        $names = [];

        foreach (['resource.host.name', 'span.server.address'] as $tag) {
            try {
                $names = [...$names, ...$this->connections->traces()->tagValues($tag, null, null, null, 200)];
            } catch (SourceException) {
                // One source of names failing should not blank the map.
            }
        }

        return array_values(array_unique(array_filter($names, static fn (string $n): bool => trim($n) !== '')));
    }

    /**
     * Every value of this exporter's identifying labels, keyed by label.
     *
     * @return array<string, list<string>>
     */
    private function instances(Exporter $exporter): array
    {
        $found = [];

        foreach ($exporter->identityLabels as $label) {
            try {
                $values = $this->connections->metrics()->labelValues($label);
            } catch (SourceException) {
                continue;
            }

            $values = array_values(array_filter($values, static fn (string $v): bool => trim($v) !== ''));

            if ($values !== []) {
                // The first label that yields anything wins: they are listed
                // in preference order, and matching the same instance twice
                // under two labels would double every target.
                return [$label => $values];
            }
        }

        return $found;
    }

    /**
     * The name this instance belongs to, or null when nothing says it does.
     *
     * @param  list<string>  $names
     */
    private function match(string $instance, array $names): ?string
    {
        $haystack = InfrastructureMap::normalise($instance);

        foreach ($names as $name) {
            if (InfrastructureMap::normalise($name) === $haystack) {
                return $name;
            }
        }

        // An exporter often names itself by its own port on the box it
        // watches (`cache-1:9121` for a Redis on `cache-1:6379`), and
        // redis_exporter's `addr` carries the real address inside a URL.
        foreach ($names as $name) {
            $needle = InfrastructureMap::normalise($name);

            if ($needle !== '' && str_contains($haystack, $needle)) {
                return $name;
            }
        }

        return null;
    }

    /**
     * Which of this exporter's signals actually return data for this
     * instance. This is what keeps a wrong metric name from being invisible:
     * it lands in `absent` instead of silently never rendering.
     *
     * @return array{list<string>, list<string>}
     */
    private function probe(Exporter $exporter, string $selector): array
    {
        $resolved = [];
        $absent = [];

        foreach ($exporter->signals as $signal) {
            try {
                $samples = $this->connections->metrics()->query(
                    new MetricQuery('', raw: $signal->promql($selector)),
                );
            } catch (SourceException) {
                $absent[] = $signal->key;

                continue;
            }

            if ($samples === []) {
                $absent[] = $signal->key;

                continue;
            }

            $resolved[] = $signal->key;
        }

        return [$resolved, $absent];
    }

    private function ttl(): int
    {
        $value = $this->config->get('telemetry-ui.discovery.ttl', 900);

        return is_numeric($value) ? max(60, (int) $value) : 900;
    }
}
