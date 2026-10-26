<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Discovery;

use Cbox\TelemetryUi\Connectors\ConnectionManager;
use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Connectors\Together;
use Cbox\TelemetryUi\Queries\Ir\MetricQuery;
use Cbox\TelemetryUi\Support\SchemaDetector;
use Cbox\TelemetryUi\Support\ScopeLabels;
use DateTimeImmutable;
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
    private const CACHE_KEY = 'discovery:map';

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
        $cached = $this->cache->store()->get($this->mapKey());

        if (is_array($cached)) {
            return InfrastructureMap::fromArray($cached);
        }

        return $this->discover();
    }

    /**
     * The targets behind these names: what a trace view needs, for the host
     * it ran on and the dependencies it called.
     *
     * A warm map answers. A cold one is not rebuilt here, because that is
     * the whole fleet (one query per signal per instance: on 184 hosts,
     * some 1,400 queries and two and a half minutes) and it would run inside
     * the request that opened the trace. Instead only these names are matched
     * and probed, with every probe sent together, and the answer is cached
     * per name for as long as the map would be.
     *
     * @param  list<string>  $names
     * @return list<DiscoveredTarget>
     */
    public function targetsFor(array $names): array
    {
        $store = $this->cache->store();
        $cached = $store->get($this->mapKey());

        if (is_array($cached)) {
            $map = InfrastructureMap::fromArray($cached);

            return array_values(array_merge([], ...array_map($map->for(...), $names)));
        }

        $targets = [];
        $missing = [];

        foreach ($names as $name) {
            $hit = $store->get($this->nameKey($name));

            if (! is_array($hit)) {
                $missing[] = $name;

                continue;
            }

            foreach ($hit as $target) {
                if (is_array($target)) {
                    $targets[] = DiscoveredTarget::fromArray($target);
                }
            }
        }

        if ($missing === []) {
            return $targets;
        }

        $found = $this->probeAll($this->candidates($missing));

        foreach ($missing as $name) {
            $mine = array_values(array_filter(
                $found,
                static fn (DiscoveredTarget $t): bool => InfrastructureMap::normalise($t->name) === InfrastructureMap::normalise($name),
            ));
            $store->put($this->nameKey($name), array_map(static fn (DiscoveredTarget $t): array => $t->toArray(), $mine), $this->ttl());
            $targets = [...$targets, ...$mine];
        }

        return $targets;
    }

    /**
     * Probe everything and replace the cached map.
     *
     * Costs one metric-name lookup, one label lookup per exporter present,
     * two tag lookups for the hosts and dependency addresses, and one query
     * per signal per matched target (sent together). Run it on a schedule —
     * infrastructure changes at deploy speed, not request speed. A request
     * that needs a few names uses {@see targetsFor()} instead.
     */
    public function discover(): InfrastructureMap
    {
        $exporters = $this->present();
        $names = $this->knownNames();
        $candidates = [];
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
                    $candidates[] = [$name, $exporter, $label, $value];
                }
            }
        }

        $targets = $this->probeAll($candidates);

        $map = new InfrastructureMap(
            targets: $targets,
            exportersPresent: array_map(static fn (Exporter $e): string => $e->key, $exporters),
            unmatchedInstances: $unmatchedInstances,
            unmatchedHosts: array_values(array_filter($names, static fn (string $n): bool => ! isset($claimed[$n]))),
            discoveredAt: time(),
        );

        $this->cache->store()->put($this->mapKey(), $map->toArray(), $this->ttl());

        return $map;
    }

    public function forget(): void
    {
        $this->cache->store()->forget($this->mapKey());
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
        // An explicit window, never null: some backends answer a tag-values
        // lookup with an empty list rather than an error when no range is
        // given, and discovery would then match nothing and say nothing.
        $lookback = $this->int('telemetry-ui.discovery.lookback', 86_400);
        $end = new DateTimeImmutable('@'.time());
        $start = new DateTimeImmutable('@'.(time() - $lookback));

        $names = [];

        foreach (['resource.host.name', 'span.server.address'] as $tag) {
            try {
                $names = [...$names, ...$this->connections->traces()->tagValues($tag, null, $start, $end, 200)];
            } catch (SourceException) {
                // One source of names failing should not blank the map.
            }
        }

        // The metrics store knows the hosts too, from the app's own labels.
        // Worth asking when the traces backend cannot enumerate tags.
        if ($names === []) {
            try {
                $names = $this->connections->metrics()->labelValues(ScopeLabels::metrics('host'));
            } catch (SourceException) {
                $names = [];
            }
        }

        return array_values(array_unique(array_filter($names, static fn (string $n): bool => trim($n) !== '')));
    }

    /**
     * Every value of this exporter's identifying labels, keyed by label.
     *
     * Scoped to this exporter's own metrics. `instance` is shared by every
     * scrape job in a Prometheus, so enumerating it unscoped hands each
     * exporter the whole fleet's instances — which is how a Redis exporter
     * ends up claiming the node exporter's host and reporting none of its
     * signals.
     *
     * @return array<string, list<string>>
     */
    private function instances(Exporter $exporter): array
    {
        $found = [];
        $match = '{__name__=~"'.str_replace('"', '', $exporter->detect).'"}';
        // The instances scraped in the last hour: a probe is an instant query
        // and finds nothing on one that went away, and without a window the
        // store answers from its whole retention (10.8 s against 0.09 s on a
        // year of Mimir), which is what timed out a trace's context.
        $end = new DateTimeImmutable('@'.time());
        $start = new DateTimeImmutable('@'.(time() - 3600));

        foreach ($exporter->identityLabels as $label) {
            try {
                $values = $this->connections->metrics()->labelValues($label, $match, $start, $end);
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
     * The exporter instances that belong to these names, as
     * [name, exporter, label, value].
     *
     * @param  list<string>  $names
     * @return list<array{string, Exporter, string, string}>
     */
    private function candidates(array $names): array
    {
        $candidates = [];

        foreach ($this->present() as $exporter) {
            foreach ($this->instances($exporter) as $label => $values) {
                foreach ($values as $value) {
                    $name = $this->match($value, $names);

                    if ($name !== null) {
                        $candidates[] = [$name, $exporter, $label, $value];
                    }
                }
            }
        }

        return $candidates;
    }

    /**
     * Which of each candidate's signals actually return data. This is what
     * keeps a wrong metric name from being invisible: it lands in `absent`
     * instead of silently never rendering. Every signal of every candidate
     * is one query, and they are all sent together.
     *
     * @param  list<array{string, Exporter, string, string}>  $candidates
     * @return list<DiscoveredTarget>
     */
    private function probeAll(array $candidates): array
    {
        $reads = [];

        foreach ($candidates as $i => [, $exporter, $label, $value]) {
            $selector = $label.'="'.str_replace('"', '', $value).'"';

            foreach ($exporter->signals as $signal) {
                $reads[$i.'|'.$signal->key] = fn (): array => $this->connections->metrics()->query(
                    new MetricQuery('', raw: $signal->promql($selector)),
                );
            }
        }

        $answers = Together::run($reads);
        $targets = [];

        foreach ($candidates as $i => [$name, $exporter, $label, $value]) {
            $resolved = [];
            $absent = [];

            foreach ($exporter->signals as $signal) {
                $answer = $answers[$i.'|'.$signal->key] ?? null;

                if (is_array($answer) && $answer !== []) {
                    $resolved[] = $signal->key;
                } else {
                    $absent[] = $signal->key;
                }
            }

            $targets[] = new DiscoveredTarget(
                name: $name,
                exporter: $exporter->key,
                instance: $value,
                matchedOn: $label,
                selector: $label.'="'.str_replace('"', '', $value).'"',
                signals: $resolved,
                absent: $absent,
            );
        }

        return $targets;
    }

    /**
     * The map is one tenant's infrastructure, so it is cached under the
     * connections in force: one tenant's discovery never answers for another.
     */
    private function mapKey(): string
    {
        return $this->connections->cacheKey(self::CACHE_KEY);
    }

    private function nameKey(string $name): string
    {
        return $this->mapKey().':'.hash('xxh128', InfrastructureMap::normalise($name));
    }

    private function ttl(): int
    {
        return $this->int('telemetry-ui.discovery.ttl', 900);
    }

    private function int(string $key, int $default): int
    {
        $value = $this->config->get($key, $default);

        return is_numeric($value) ? max(60, (int) $value) : $default;
    }
}
