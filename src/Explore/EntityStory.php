<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Explore;

use Cbox\TelemetryUi\Connectors\ConnectionManager;
use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Dimensions\Dimension;
use Cbox\TelemetryUi\Dimensions\Dimensions;
use Cbox\TelemetryUi\Http\Api\Filter;
use Cbox\TelemetryUi\Http\Api\RequestScope;
use Cbox\TelemetryUi\Http\Api\Serializer;
use Cbox\TelemetryUi\Panels\Panel;
use Cbox\TelemetryUi\Queries\Ir\LogQuery;
use Cbox\TelemetryUi\Queries\Ir\MatchOp;
use Cbox\TelemetryUi\Queries\Ir\TraceCondition;
use Cbox\TelemetryUi\Support\Annotation;
use Cbox\TelemetryUi\Support\Annotations;
use Cbox\TelemetryUi\Support\Format;
use Cbox\TelemetryUi\Support\ScopeLabels;
use Cbox\TelemetryUi\TelemetryUiManager;

/**
 * Entity pages: an entity is a dimension value (route = `http.route`,
 * query = `db.query.text`, customer = `billing.customer_id`, …), so one template
 * serves every type, scoped by one facet.
 *
 * The page tells a story rather than dumping attributes: a RED headline and
 * trend, plain-language insights (where failures concentrate, what changed),
 * the dimensions that contribute most, slowest and failing example traces,
 * correlated error groups and deploys. Raw attributes come last.
 *
 * @phpstan-import-type Row from SpanExplorer
 */
final class EntityStory
{
    public const LIMIT = 500;

    public function __construct(
        private readonly ConnectionManager $connections,
        private readonly Dimensions $dimensions,
        private readonly SpanExplorer $spans,
        private readonly TelemetryUiManager $manager,
    ) {}

    public function dimension(string $type): ?Dimension
    {
        return $this->dimensions->forEntity($type);
    }

    /**
     * The signal an entity's spans live on: request-level attributes are on
     * server spans; the rest (queries, views, jobs, outgoing calls) anywhere.
     */
    public function signalFor(Dimension $dimension): string
    {
        return in_array('requests', $dimension->signals, true) || ! $dimension->builtin ? 'requests' : 'traces';
    }

    /**
     * The entity index: every value of the dimension within scope, with RED.
     *
     * @return array<string, mixed>
     */
    public function index(RequestScope $scope, Dimension $dimension): array
    {
        $signal = $this->signalFor($dimension);
        // A derived dimension has no attribute of its own: "present" is a
        // source value shaped like its pattern, and the rows must carry that
        // source attribute for the value to be read back out.
        $derived = $dimension->derived;
        $present = $derived === null
            ? TraceCondition::nil($dimension->traceField())
            : TraceCondition::re($this->dimensions->resolve($derived->from)->traceField(), $derived->regex());
        $keys = $derived === null ? [$dimension->key] : [$dimension->key, $derived->from];

        $rows = $signal === 'requests'
            ? $this->spans->rows($scope, $signal, self::LIMIT, [$present], $keys)
            : $this->spans->rowsFrom($this->spans->spanSample($scope, [$present], $keys), $signal, $keys, true);

        if ($rows === [] && $signal === 'requests') {
            $signal = 'traces';
            $rows = $this->spans->rowsFrom($this->spans->spanSample($scope, [$present], $keys), $signal, $keys, true);
        }

        if ($signal !== 'requests') {
            $rows = $this->spans->markErrors($rows, $scope, $signal, [$present], 200);
        }

        $groups = array_values(array_filter(
            Stats::groupBy($rows, $dimension->key, 200),
            static fn (array $g): bool => $g['value'] !== '(none)',
        ));

        return [
            'entity' => $this->describe($dimension),
            'signal' => $signal,
            'unit' => $signal === 'requests' ? 'requests' : 'spans',
            'values' => $groups,
            'stats' => Stats::red($rows, $scope->rangeSeconds()),
            'sample' => ['size' => count($rows), 'limit' => $this->limitFor($signal), 'truncated' => count($rows) >= $this->limitFor($signal), 'exact' => false],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function story(RequestScope $scope, Dimension $dimension, string $value): array
    {
        $signal = $this->signalFor($dimension);
        $match = $this->matchCondition($dimension, $value);
        $keys = $this->breakdownKeys($dimension);

        $summaries = $signal === 'requests'
            ? $this->spans->summaries($scope, $signal, self::LIMIT, [$match], $keys)
            : $this->spans->spanSample($scope, [$match], $keys);

        if ($summaries === [] && $signal === 'requests') {
            $signal = 'traces';
            $summaries = $this->spans->spanSample($scope, [$match], $keys);
        }

        $rows = $this->spans->rowsFrom($summaries, $signal, $keys, $signal !== 'requests');

        if ($signal !== 'requests') {
            $rows = $this->spans->markErrors($rows, $scope, $signal, [$match], 200);
        }

        [$start, $end] = $scope->range();
        $startMs = $start->getTimestamp() * 1000;
        $endMs = $end->getTimestamp() * 1000;

        $red = Stats::red($rows, $scope->rangeSeconds());
        $failing = array_values(array_filter($rows, static fn (array $r): bool => $r['status'] !== null && (int) $r['status'] >= 400 || $r['error']));
        $breakdowns = $this->breakdowns($rows, $failing, $signal === 'requests' ? $keys : ['trace.root', ...$keys]);
        $errors = $this->correlatedErrors($scope, $rows);
        $deploys = $this->deploys($scope);

        $slowest = $rows;
        usort($slowest, static fn (array $a, array $b): int => $b['durationMs'] <=> $a['durationMs']);

        $raw = [];

        foreach ($summaries as $summary) {
            $span = $this->spans->representative($summary, $signal);

            if ($span !== null) {
                $raw = Serializer::attributes($span->attributes);
                break;
            }
        }

        return [
            'entity' => [...$this->describe($dimension), 'value' => $value, 'linkOut' => $dimension->linkFor($value)],
            'signal' => $signal,
            'where' => [$dimension->key.'='.$value],
            'red' => $red,
            'series' => Stats::series($rows, $startMs, $endMs),
            'heatmap' => Stats::heatmap($rows, $startMs, $endMs),
            'statusMix' => Stats::groupBy($rows, 'http.response.status_code', 12),
            'insights' => $this->insights($dimension, $value, $rows, $failing, $breakdowns, $errors, $deploys, $red),
            'breakdowns' => $breakdowns,
            'slowest' => array_slice($slowest, 0, 6),
            'failing' => array_slice($failing, 0, 6),
            'recent' => array_slice($rows, 0, 50),
            'errors' => $errors,
            'deploys' => $deploys,
            'raw' => $raw,
            'panels' => $this->panels($dimension, $value),
            'sample' => ['size' => count($rows), 'limit' => $this->limitFor($signal), 'truncated' => count($rows) >= $this->limitFor($signal), 'exact' => false],
            'range' => ['start' => $startMs, 'end' => $endMs],
        ];
    }

    /**
     * The sample bound reported to the client for a signal.
     */
    private function limitFor(string $signal): int
    {
        return $signal === 'requests' ? self::LIMIT : 1500;
    }

    /**
     * @return array{type: string, key: string, label: string, plural: string, group: string|null, custom: bool, linksOut: bool}
     */
    public function describe(Dimension $dimension): array
    {
        return [
            'type' => $dimension->entitySlug(),
            'key' => $dimension->key,
            'label' => $dimension->label,
            'plural' => $dimension->plural ?? $dimension->label.'s',
            'group' => $dimension->group,
            'custom' => ! $dimension->builtin,
            'linksOut' => $dimension->link !== null,
        ];
    }

    private function matchCondition(Dimension $dimension, string $value): TraceCondition
    {
        $filter = new Filter($dimension->key, '=', $value);

        return TraceFilters::condition($filter, $this->dimensions) ?? TraceCondition::eq($dimension->traceField(), $value);
    }

    /**
     * The dimensions worth breaking an entity down by: every declared custom
     * dimension plus the request-level built-ins, minus the entity itself.
     *
     * @return list<string>
     */
    private function breakdownKeys(Dimension $self): array
    {
        $keys = [];

        foreach ($this->dimensions->all() as $dimension) {
            if ($dimension->key === $self->key || $dimension->scope === 'intrinsic') {
                continue;
            }

            if (! $dimension->builtin || in_array($dimension->key, ['http.route', 'http.response.status_code', 'user.id', 'client.address', 'host.name', 'http.request.method', 'geo.country.iso_code', 'service.name'], true)) {
                $keys[] = $dimension->key;
            }
        }

        return $keys;
    }

    /**
     * Top values per breakdown dimension, with how over-represented each is
     * among failures (lift > 1 = failures concentrate there).
     *
     * @param  list<Row>  $rows
     * @param  list<Row>  $failing
     * @param  list<string>  $keys
     * @return list<array{key: string, label: string, custom: bool, entity: string, distinct: int, values: list<array{value: string, count: int, share: float, failing: int, lift: float|null}>, drill: bool}>
     */
    private function breakdowns(array $rows, array $failing, array $keys): array
    {
        $out = [];
        $total = max(1, count($rows));
        $failTotal = count($failing);

        foreach ($keys as $key) {
            $counts = [];
            $fails = [];

            foreach ($rows as $row) {
                if (($v = $row['attributes'][$key] ?? '') !== '') {
                    $counts[$v] = ($counts[$v] ?? 0) + 1;
                }
            }

            if ($counts === []) {
                continue;
            }

            foreach ($failing as $row) {
                if (($v = $row['attributes'][$key] ?? '') !== '') {
                    $fails[$v] = ($fails[$v] ?? 0) + 1;
                }
            }

            arsort($counts);
            $dimension = $this->dimensions->resolve($key);
            $values = [];

            foreach (array_slice($counts, 0, 6, true) as $value => $count) {
                $share = $count / $total;
                $failCount = $fails[$value] ?? 0;
                $values[] = [
                    'value' => (string) $value,
                    'count' => $count,
                    'share' => $share,
                    'failing' => $failCount,
                    'lift' => $failTotal > 0 && $share > 0 ? ($failCount / $failTotal) / $share : null,
                ];
            }

            $out[] = [
                'key' => $key,
                'label' => $key === 'trace.root' ? 'Called from' : $dimension->label,
                'custom' => ! $dimension->builtin && $this->dimensions->get($key) !== null,
                'entity' => $dimension->entitySlug(),
                'distinct' => count($counts),
                'values' => $values,
                // trace.root is read-side (the trace's root name), not a
                // queryable attribute: show it, but don't offer filter/group.
                'drill' => $key !== 'trace.root',
            ];
        }

        // Custom (host-declared) dimensions first — they are why the host declared them.
        usort($out, static fn (array $a, array $b): int => [$b['key'] === 'trace.root', $b['custom'], $b['values'][0]['count']] <=> [$a['key'] === 'trace.root', $a['custom'], $a['values'][0]['count']]);

        return $out;
    }

    /**
     * Exception groups whose occurrences belong to traces in this entity's
     * sample — "this route throws ValidationException" without guessing.
     *
     * @param  list<Row>  $rows
     * @return list<array{group: string, type: string, message: string, count: int, traceId: string|null}>
     */
    private function correlatedErrors(RequestScope $scope, array $rows): array
    {
        $traceIds = array_flip(array_map(static fn (array $r): string => $r['traceId'], $rows));

        if ($traceIds === []) {
            return [];
        }

        [$start, $end] = $scope->range();

        // Read only the streams of the services these traces ran in. With no
        // service selected the scope's selector is "any service", which makes
        // the store scan every stream it has — seconds on a busy one, for
        // records that can only belong to these services anyway.
        $query = $scope->logQuery();

        if ($query->stream === []) {
            $services = array_values(array_unique(array_filter(array_map(static fn (array $r): string => (string) $r['service'], $rows))));
            $query = new LogQuery([ScopeLabels::logServiceMatcher($services)], $query->pipeline, $query->raw);
        }

        try {
            $entries = $this->connections->logs()->query(
                $query->whereLabel('exception_group', MatchOp::Neq, ''),
                $start,
                $end,
                500,
            );
        } catch (SourceException) {
            return [];
        }

        $groups = [];

        foreach ($entries as $entry) {
            $traceId = $entry->labels['trace_id'] ?? '';
            $group = $entry->labels['exception_group'] ?? '';

            if ($group === '' || ! isset($traceIds[$traceId])) {
                continue;
            }

            $groups[$group] ??= ['group' => $group, 'type' => $entry->labels['exception_type'] ?? '', 'message' => $entry->labels['exception_message'] ?? '', 'count' => 0, 'traceId' => $traceId];
            $groups[$group]['count']++;
        }

        $groups = array_values($groups);
        usort($groups, static fn (array $a, array $b): int => $b['count'] <=> $a['count']);

        return array_slice($groups, 0, 8);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function deploys(RequestScope $scope): array
    {
        [$start, $end] = $scope->range();

        try {
            return array_map(
                static fn (Annotation $a): array => $a->toMarkLine(),
                app(Annotations::class)->between($start, $end, $scope->logQuery()),
            );
        } catch (SourceException) {
            return [];
        }
    }

    /**
     * Plain-language findings, most important first. Each is `{tone, text}`;
     * `text` may reference a dimension value via `{key, value}` so the SPA can
     * make it clickable.
     *
     * @param  list<Row>  $rows
     * @param  list<Row>  $failing
     * @param  list<array{key: string, label: string, custom: bool, entity: string, distinct: int, values: list<array{value: string, count: int, share: float, failing: int, lift: float|null}>, drill: bool}>  $breakdowns
     * @param  list<array{group: string, type: string, message: string, count: int, traceId: string|null}>  $errors
     * @param  list<array<string, mixed>>  $deploys
     * @param  array{count: int, errors: int, errorRate: float, avg: float|null, p50: float|null, p95: float|null, p99: float|null, traces: int, perMinute: float}  $red
     * @return list<array{tone: string, text: string, dim?: array{key: string, value: string}, link?: array<string, string>}>
     */
    private function insights(Dimension $dimension, string $value, array $rows, array $failing, array $breakdowns, array $errors, array $deploys, array $red): array
    {
        if ($rows === []) {
            return [['tone' => 'dim', 'text' => "No spans for {$dimension->label} {$value} in this window. Try a longer period."]];
        }

        $out = [];
        $count = count($rows);
        $failCount = count($failing);

        if ($failCount > 0) {
            $statuses = [];

            foreach ($failing as $row) {
                $statuses[$row['status'] ?? 'error'] = ($statuses[$row['status'] ?? 'error'] ?? 0) + 1;
            }

            arsort($statuses);
            $top = (string) array_key_first($statuses);
            $serverSide = $red['errors'] > 0;
            $http = $top !== 'error';

            $out[] = [
                'tone' => $serverSide ? 'danger' : 'warn',
                'text' => Format::percent($failCount / $count).' of '.Format::count($count).' failed'
                    .($http ? ' — mostly '.$top : '')
                    .match (true) {
                        ! $http => '.',
                        $serverSide => ' ('.$red['errors'].' server errors).',
                        default => '. All client errors (4xx): a caller or data problem, not an outage.',
                    },
            ];
        } else {
            $out[] = ['tone' => 'ok', 'text' => Format::count($count).' in this window, none failed.'];
        }

        if ($red['p95'] !== null) {
            $out[] = ['tone' => $red['p95'] > 1000 ? 'warn' : 'dim', 'text' => 'Latency p50 '.Format::ms((float) $red['p50']).', p95 '.Format::ms((float) $red['p95']).'.'];
        }

        foreach ($breakdowns as $breakdown) {
            $topValue = $breakdown['values'][0];

            if ($failCount >= 3 && $topValue['failing'] / $failCount >= 0.5 && $breakdown['distinct'] > 1) {
                $out[] = [
                    'tone' => 'warn',
                    'text' => "Failures concentrate on {$breakdown['label']} {$topValue['value']} ({$topValue['failing']} of {$failCount}).",
                    ...($breakdown['drill'] ? ['dim' => ['key' => $breakdown['key'], 'value' => $topValue['value']]] : []),
                ];
            } elseif ($count >= 5 && $topValue['share'] >= 0.6 && $breakdown['distinct'] > 1 && $breakdown['custom']) {
                $out[] = [
                    'tone' => 'info',
                    'text' => Format::percent($topValue['share'])." of traffic is {$breakdown['label']} {$topValue['value']}.",
                    'dim' => ['key' => $breakdown['key'], 'value' => $topValue['value']],
                ];
            }
        }

        if ($errors !== []) {
            $out[] = [
                'tone' => 'danger',
                'text' => "Throws {$errors[0]['type']} ({$errors[0]['count']}×)".(count($errors) > 1 ? ' and '.(count($errors) - 1).' other exception group(s).' : '.'),
                'link' => ['to' => 'error', 'group' => $errors[0]['group']],
            ];
        }

        if ($failing !== [] && $deploys !== []) {
            $firstFail = min(array_map(static fn (array $r): int => $r['startMs'], $failing));

            foreach (array_reverse($deploys) as $deploy) {
                $at = (float) ($deploy['xAxis'] ?? 0);

                if ($at <= $firstFail && $firstFail - $at < 6 * 3_600_000) {
                    $out[] = ['tone' => 'warn', 'text' => 'First failure '.self::gap((int) ($firstFail - $at)).' after deploy '.((string) ($deploy['label'] ?? '')).' — suspect.'];
                    break;
                }
            }
        }

        return $out;
    }

    private static function gap(int $ms): string
    {
        $minutes = intdiv($ms, 60_000);

        return $minutes < 60 ? $minutes.'m' : intdiv($minutes, 60).'h '.($minutes % 60).'m';
    }

    /**
     * The v1-style detail panels attached to this entity type, with the
     * param that scopes them (`route` → request-detail panels with ?route=).
     *
     * @return list<array{id: string, span: int, params: array<string, string>}>
     */
    private function panels(Dimension $dimension, string $value): array
    {
        $page = $this->manager->entityPageFor($dimension->entitySlug());

        if ($page === null) {
            return [];
        }

        return array_map(static fn (string $panel): array => [
            'id' => $panel::id(),
            'span' => $panel::span(),
            'params' => [$page['param'] => $value, '_page' => $page['page']],
        ], array_values(array_filter(
            $this->manager->panels($page['page']),
            static fn (string $panel): bool => is_subclass_of($panel, Panel::class),
        )));
    }
}
