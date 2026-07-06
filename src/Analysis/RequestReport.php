<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Analysis;

use Cbox\TelemetryUi\Queries\Results\Span;
use Cbox\TelemetryUi\Queries\Results\SpanKind;
use Cbox\TelemetryUi\Queries\Results\Trace;
use Cbox\TelemetryUi\Support\Format;

/**
 * Turns a raw trace into the story of a request: what it was (method,
 * route, IP, user, headers), what it did (queries, cache ops, redis
 * commands, upstream calls, dispatched jobs, rendered views) and what it
 * cost — each as a readable section instead of a span waterfall. The
 * waterfall stays available as the raw, last-resort view.
 *
 * Works for any unit of work: a queue job's trace has no HTTP facts but
 * its database/cache/outgoing sections light up the same way.
 *
 * @phpstan-type Item array{name: string, detail: string, durationMs: float, spanId: string}
 */
final class RequestReport
{
    /**
     * @return array{
     *     request: array<string, string>,
     *     requestHeaders: array<string, string>,
     *     responseHeaders: array<string, string>,
     *     totals: list<array{label: string, value: string}>,
     *     db: array{items: list<Item>, duplicates: array<string, int>},
     *     cache: array{items: list<Item>, summary: array<string, int>},
     *     redis: list<Item>,
     *     outgoing: list<Item>,
     *     queued: list<Item>,
     *     views: list<Item>,
     *     storage: list<Item>,
     * }
     */
    public static function from(Trace $trace): array
    {
        $root = $trace->root();
        $rootAttributes = $root !== null ? $root->attributes : [];

        $db = ['items' => [], 'duplicates' => []];
        $cache = ['items' => [], 'summary' => []];
        $redis = [];
        $outgoing = [];
        $queued = [];
        $views = [];
        $storage = [];

        /** @var array<string, int> $sqlSeen */
        $sqlSeen = [];

        foreach ($trace->spans as $span) {
            $name = $span->name;

            // Database queries — the statement is the story.
            if (isset($span->attributes['db.query.text'])) {
                $sql = self::str($span->attributes['db.query.text']);
                $sqlSeen[$sql] = ($sqlSeen[$sql] ?? 0) + 1;

                $db['items'][] = self::item($span, $sql, self::str($span->attributes['db.namespace'] ?? $span->attributes['db.system.name'] ?? ''));

                continue;
            }

            // Cache operations (cache.hit / cache.miss / cache.write / …).
            if (str_starts_with($name, 'cache.')) {
                $op = substr($name, 6);
                $cache['summary'][$op] = ($cache['summary'][$op] ?? 0) + 1;
                $cache['items'][] = self::item($span, self::str($span->attributes['cache.key'] ?? $span->attributes['cache.key.group'] ?? ''), $op);

                continue;
            }

            // Redis commands (span names are "redis GET", "redis SET", …).
            if (str_starts_with($name, 'redis')) {
                $redis[] = self::item($span, self::str($span->attributes['db.operation.name'] ?? $span->attributes['redis.command'] ?? $name), '');

                continue;
            }

            // Dispatched jobs / published messages.
            if ($span->kind === SpanKind::Producer) {
                $queued[] = self::item($span, self::str($span->attributes['messaging.destination.name'] ?? ''), '');

                continue;
            }

            // Upstream HTTP calls (client spans that aren't browser/RUM).
            if ($span->kind === SpanKind::Client && ! $span->isBrowser()
                && (isset($span->attributes['http.url']) || isset($span->attributes['url.full']) || isset($span->attributes['server.address']))) {
                $url = self::str($span->attributes['http.url'] ?? $span->attributes['url.full'] ?? $span->attributes['server.address']);
                $status = self::str($span->attributes['http.response.status_code'] ?? '');

                $outgoing[] = self::item($span, $url, $status);

                continue;
            }

            // Rendered views + Livewire phases.
            if (isset($span->attributes['view.name']) || str_starts_with($name, 'livewire.')) {
                $views[] = self::item($span, self::str($span->attributes['view.name'] ?? $span->attributes['livewire.component'] ?? ''), str_starts_with($name, 'livewire.') ? $name : 'view');

                continue;
            }

            // Storage / filesystem operations.
            if (str_starts_with($name, 'storage ')) {
                $storage[] = self::item($span, self::str($span->attributes['storage.path'] ?? ''), self::str($span->attributes['storage.disk'] ?? ''));
            }
        }

        // Slowest first — that's what you came to see.
        foreach ([&$db['items'], &$cache['items'], &$redis, &$outgoing, &$views, &$storage] as &$list) {
            usort($list, static fn (array $a, array $b): int => $b['durationMs'] <=> $a['durationMs']);
        }
        unset($list);

        $db['duplicates'] = array_filter($sqlSeen, static fn (int $count): bool => $count > 1);
        arsort($db['duplicates']);

        return [
            'request' => self::request($root, $rootAttributes),
            'requestHeaders' => self::headers($rootAttributes, 'http.request.header.'),
            'responseHeaders' => self::headers($rootAttributes, 'http.response.header.'),
            'totals' => self::totals($rootAttributes),
            'db' => $db,
            'cache' => $cache,
            'redis' => $redis,
            'outgoing' => $outgoing,
            'queued' => $queued,
            'views' => $views,
            'storage' => $storage,
        ];
    }

    /**
     * The human facts of the unit of work. HTTP fields for requests; a
     * job/command trace simply has fewer rows.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, string>
     */
    private static function request(?Span $root, array $attributes): array
    {
        $get = static fn (string $key): string => self::str($attributes[$key] ?? '');

        $facts = array_filter([
            'method' => $get('http.request.method'),
            'route' => $get('http.route'),
            'path' => $get('url.path').($get('url.query') !== '' ? '?'.$get('url.query') : ''),
            'status' => $get('http.response.status_code'),
            'ip' => $get('client.address'),
            'user' => $get('enduser.id') !== '' ? '#'.$get('enduser.id').($get('enduser.guard') !== '' ? ' ('.$get('enduser.guard').')' : '') : '',
            'user agent' => $get('user_agent.original'),
            'request size' => $get('http.request.body.size') !== '' ? Format::bytes((float) $get('http.request.body.size')) : '',
            'response size' => $get('http.response.body.size') !== '' ? Format::bytes((float) $get('http.response.body.size')) : '',
            'command' => $get('laravel.command'),
            'job' => $get('messaging.destination.name'),
        ], static fn (string $value): bool => $value !== '');

        if ($facts === [] && $root !== null) {
            $facts['origin'] = $root->name;
        }

        return $facts;
    }

    /**
     * Captured request/response headers (opt-in on the emitting side).
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, string>
     */
    private static function headers(array $attributes, string $prefix): array
    {
        $headers = [];

        foreach ($attributes as $key => $value) {
            if (str_starts_with($key, $prefix)) {
                $headers[substr($key, strlen($prefix))] = self::str($value);
            }
        }

        ksort($headers);

        return $headers;
    }

    /**
     * The root span's cost tallies, humanized.
     *
     * @param  array<string, mixed>  $attributes
     * @return list<array{label: string, value: string}>
     */
    private static function totals(array $attributes): array
    {
        $map = [
            'db.query.count' => 'queries',
            'db.query.time_ms' => 'query time',
            'db.query.duplicate.count' => 'N+1 queries',
            'db.transaction.count' => 'transactions',
            'cache.event.count' => 'cache ops',
            'redis.command.count' => 'redis commands',
            'model.hydrations' => 'models hydrated',
            'view.render.count' => 'views',
            'storage.operation.count' => 'storage ops',
            'php.cpu.time_ms' => 'CPU time',
            'php.memory.delta_bytes' => 'memory',
        ];

        $totals = [];

        foreach ($map as $key => $label) {
            if (! isset($attributes[$key]) || ! is_numeric($attributes[$key])) {
                continue;
            }

            $value = (float) $attributes[$key];

            $totals[] = [
                'label' => $label,
                'value' => match (true) {
                    str_ends_with($key, '_ms') => Format::ms($value),
                    str_ends_with($key, '_bytes') => Format::bytes($value),
                    default => Format::count($value),
                },
            ];
        }

        return $totals;
    }

    /**
     * @return Item
     */
    private static function item(Span $span, string $detail, string $name): array
    {
        return [
            'name' => $name,
            'detail' => $detail,
            'durationMs' => $span->durationMs(),
            'spanId' => $span->spanId,
        ];
    }

    private static function str(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
