<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Panels;

use Cbox\TelemetryUi\Panels\Concerns\BuildsCharts;

/**
 * Builders for the panel JSON contract the SPA renders — the one place the
 * payload shapes are spelled, mirrored 1:1 by `resources/app/src/api/types.ts`.
 *
 * Every panel payload is an array with a `kind` the SPA has a renderer for:
 * chart, stats, table, bars, composite, heatmap, graph, logs, header, kv, code,
 * callout and hidden. Common optional keys on any kind: title, subtitle, span
 * (grid columns 1–3), error, empty, note, drill (a {@see Link}), controls.
 *
 * Kinds without a builder here are plain arrays:
 * - heatmap: `{xs: list<int ms>, ys: list<string>, cells: list<[xi, yi, value]>, unit?, link?}`
 * - graph:   `{nodes: list<{id, label, kind?, color?, requests?, errors?, p95?, link?}>,
 *             edges: list<{source, target, count, errors?, p95?}>}`
 * - logs:    `{entries: list<{time, ms, level, tone, message, labels: map, traceId?}>,
 *             stream?: {signal: 'logs'|'requests', params: map}}` — stream enables SSE live-tail
 * - chart:   see {@see BuildsCharts::chartCard()}
 *
 * Links are data, never URLs: the SPA owns routing (and the base path), so a
 * drill-down says *what* it opens — an entity, a trace, an error group — and the
 * client decides where that lives.
 *
 * @phpstan-type Link array{to: string, type?: string, value?: string, id?: string, group?: string, page?: string, params?: array<string, string>, signal?: string, where?: list<string>, href?: string, label?: string}
 * @phpstan-type Cell array{v: string|int|float|null, raw?: float|int|null, tone?: string|null, mono?: bool, link?: Link, spark?: list<float>, bar?: float, badge?: string, dim?: array{key: string, value: string}, sub?: string}
 * @phpstan-type Column array{key: string, label: string, align?: string, width?: string}
 * @phpstan-type Stat array{label: string, value: string, tone?: string|null, delta?: string, deltaTone?: string, points?: list<float>, sparkColor?: string, link?: Link}
 * @phpstan-type Control array{param: string, label: string, type: string, value: string, options?: list<array{value: string, label: string}>, placeholder?: string}
 */
final class Ui
{
    // ---- links --------------------------------------------------------------

    /**
     * An entity page — a dimension value (route, query, host, customer, …).
     *
     * @return Link
     */
    public static function entity(string $type, string $value): array
    {
        return ['to' => 'entity', 'type' => $type, 'value' => $value];
    }

    /**
     * The list of every value of an entity type (all routes, all queries…).
     *
     * @return Link
     */
    public static function entityIndex(string $type): array
    {
        return ['to' => 'entities', 'type' => $type];
    }

    /** @return Link */
    public static function trace(string $traceId): array
    {
        return ['to' => 'trace', 'id' => $traceId];
    }

    /** @return Link */
    public static function error(string $group): array
    {
        return ['to' => 'error', 'group' => $group];
    }

    /** @return Link */
    public static function issue(string $id): array
    {
        return ['to' => 'issue', 'id' => $id];
    }

    /**
     * A registered dashboard page, optionally with extra params.
     *
     * @param  array<string, string>  $params
     * @return Link
     */
    public static function page(string $page, array $params = []): array
    {
        return array_filter(['to' => 'page', 'page' => $page, 'params' => $params], static fn ($v): bool => $v !== []);
    }

    /**
     * The Explore surface for a signal, pre-filtered — optionally with its
     * own window (`from`/`to` unix seconds) or a `groupBy`.
     *
     * @param  list<string>  $where  `key<op>value` filters
     * @param  array<string, string>  $params  from, to, groupBy, q
     * @return Link
     */
    public static function explore(string $signal, array $where = [], array $params = []): array
    {
        return ['to' => 'explore', 'signal' => $signal, 'where' => $where] + ($params !== [] ? ['params' => $params] : []);
    }

    /**
     * Explore in a window around a moment (unix seconds) — "what else happened
     * then": the lines, requests or errors around an occurrence or a deploy.
     *
     * @param  list<string>  $where
     * @return Link
     */
    public static function around(string $signal, int $at, int $before = 60, int $after = 60, array $where = []): array
    {
        return self::explore($signal, $where, ['from' => (string) ($at - $before), 'to' => (string) ($at + $after)]);
    }

    /**
     * Set one of this panel's own params (e.g. click an IP to filter the
     * request log to it) — the in-panel equivalent of Livewire's `$set`.
     *
     * @return Link
     */
    public static function param(string $param, string $value): array
    {
        return ['to' => 'param', 'params' => [$param => $value]];
    }

    /** @return Link */
    public static function url(string $href): array
    {
        return ['to' => 'url', 'href' => $href];
    }

    // ---- cells & columns ----------------------------------------------------

    /**
     * @param  array{raw?: float|int|null, tone?: string|null, mono?: bool, link?: Link, spark?: list<float>, bar?: float, badge?: string, dim?: array{key: string, value: string}, sub?: string}  $opts
     * @return Cell
     */
    public static function cell(string|int|float|null $value, array $opts = []): array
    {
        return ['v' => $value, ...array_filter($opts, static fn ($v): bool => $v !== null)];
    }

    /**
     * @return Column
     */
    public static function col(string $key, string $label, string $align = 'left', ?string $width = null): array
    {
        return array_filter(['key' => $key, 'label' => $label, 'align' => $align === 'left' ? null : $align, 'width' => $width], static fn ($v): bool => $v !== null);
    }

    /** @return Column */
    public static function num(string $key, string $label): array
    {
        return self::col($key, $label, 'right');
    }

    // ---- payloads -----------------------------------------------------------

    /**
     * A table. Each row is a map of column key → cell (or plain scalar), plus
     * an optional `_link` that makes the whole row a drill-down.
     *
     * @param  list<Column>  $columns
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    public static function table(string $title, array $columns, array $rows, array $extra = []): array
    {
        return ['kind' => 'table', 'title' => $title, 'columns' => $columns, 'rows' => $rows, ...$extra];
    }

    /**
     * Ranked label → value bars (top pages, countries, …).
     *
     * @param  list<array{label: string, value: float|int, display?: string, link?: Link, sub?: string, tone?: string}>  $items
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    public static function bars(string $title, array $items, array $extra = []): array
    {
        return ['kind' => 'bars', 'title' => $title, 'items' => $items, ...$extra];
    }

    /**
     * @param  list<Stat>  $items
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    public static function stats(string $title, array $items, array $extra = []): array
    {
        return ['kind' => 'stats', 'title' => $title, 'items' => $items, ...$extra];
    }

    /**
     * Several payloads stacked in one panel (e.g. a stats row over a table).
     *
     * @param  list<array<string, mixed>>  $parts
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    public static function composite(string $title, array $parts, array $extra = []): array
    {
        return ['kind' => 'composite', 'title' => $title, 'parts' => $parts, ...$extra];
    }

    /**
     * An entity-page header: title, eyebrow, headline stats, badges and links.
     *
     * @param  list<Stat>  $stats
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    public static function header(string $title, string $subtitle, array $stats = [], array $extra = []): array
    {
        return ['kind' => 'header', 'title' => $title, 'subtitle' => $subtitle, 'stats' => $stats, ...$extra];
    }

    /**
     * Label/value pairs.
     *
     * @param  list<array{label: string, value: string|int|float|null, mono?: bool, link?: Link, tone?: string}>  $items
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    public static function kv(string $title, array $items, array $extra = []): array
    {
        return ['kind' => 'kv', 'title' => $title, 'items' => $items, ...$extra];
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    public static function code(string $title, string $text, string $language = 'text', array $extra = []): array
    {
        return ['kind' => 'code', 'title' => $title, 'text' => $text, 'language' => $language, ...$extra];
    }

    /**
     * A message panel (tone: info, warn, danger, ok).
     *
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    public static function callout(string $title, string $message, string $tone = 'info', array $extra = []): array
    {
        return ['kind' => 'callout', 'title' => $title, 'message' => $message, 'tone' => $tone, ...$extra];
    }

    /**
     * A panel that has decided not to show (feature absent, not applicable).
     *
     * @return array{kind: string}
     */
    public static function hidden(): array
    {
        return ['kind' => 'hidden'];
    }

    /**
     * A select control bound to a panel param — the SPA renders it in the
     * panel header and re-fetches the panel with `?{param}=value`.
     *
     * @param  list<array{value: string, label: string}>  $options
     * @return Control
     */
    public static function select(string $param, string $label, string $value, array $options): array
    {
        return ['param' => $param, 'label' => $label, 'type' => 'select', 'value' => $value, 'options' => $options];
    }

    /**
     * A free-text control bound to a panel param.
     *
     * @return Control
     */
    public static function search(string $param, string $label, string $value, string $placeholder = ''): array
    {
        return ['param' => $param, 'label' => $label, 'type' => 'search', 'value' => $value, 'placeholder' => $placeholder];
    }

    /**
     * @param  list<string>  $values
     * @return list<array{value: string, label: string}>
     */
    public static function options(array $values, bool $withAll = true): array
    {
        $options = $withAll ? [['value' => '', 'label' => 'All']] : [];

        foreach ($values as $value) {
            $options[] = ['value' => $value, 'label' => $value];
        }

        return $options;
    }
}
