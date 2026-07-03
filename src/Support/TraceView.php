<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Support;

use Cbox\TelemetryUi\Queries\Results\Span;
use Cbox\TelemetryUi\Queries\Results\Trace;

/**
 * Turns a Trace into the view data both the full trace page and the slide-in
 * drawer render: the waterfall rows (with nesting/layout), the infra request
 * chain, and per-service identities.
 */
final class TraceView
{
    /**
     * Flatten the span tree depth-first into waterfall rows with layout
     * offsets relative to the trace's own window, plus collapse metadata.
     *
     * @return list<array{span: Span, depth: int, offsetPct: float, widthPct: float, ancestors: list<string>, children: int}>
     */
    public static function waterfall(Trace $trace): array
    {
        if ($trace->spans === []) {
            return [];
        }

        $traceStart = min(array_map(static fn (Span $span): int => $span->startNano, $trace->spans));
        $traceEnd = max(array_map(static fn (Span $span): int => $span->endNano, $trace->spans));
        $window = max(1, $traceEnd - $traceStart);

        $spanIds = [];
        foreach ($trace->spans as $span) {
            $spanIds[$span->spanId] = true;
        }

        /** @var array<string, list<Span>> $children */
        $children = [];
        $roots = [];

        foreach ($trace->spans as $span) {
            // Orphans (parent dropped by tail sampling) surface as top-level.
            if ($span->parentSpanId !== null && isset($spanIds[$span->parentSpanId])) {
                $children[$span->parentSpanId][] = $span;
            } else {
                $roots[] = $span;
            }
        }

        $rows = [];

        /** @var list<array{Span, int, list<string>}> $stack */
        $stack = [];
        foreach (array_reverse($roots) as $root) {
            $stack[] = [$root, 0, []];
        }

        while ($stack !== []) {
            [$span, $depth, $ancestors] = array_pop($stack);

            $rows[] = [
                'span' => $span,
                'depth' => $depth,
                'offsetPct' => (float) ((($span->startNano - $traceStart) / $window) * 100),
                'widthPct' => (float) max(0.35, (($span->endNano - $span->startNano) / $window) * 100),
                'ancestors' => $ancestors,
                'children' => count($children[$span->spanId] ?? []),
            ];

            foreach (array_reverse($children[$span->spanId] ?? []) as $child) {
                $stack[] = [$child, $depth + 1, [...$ancestors, $span->spanId]];
            }
        }

        return $rows;
    }

    /**
     * The request chain (server spans in start order): edge proxy → app.
     *
     * @return list<array{span: Span, kind: string, color: string}>
     */
    public static function chain(Trace $trace): array
    {
        return array_map(static fn (Span $span): array => [
            'span' => $span,
            'kind' => ServiceIdentity::kind($span->serviceName, $trace->services[$span->serviceName] ?? []),
            'color' => ServiceIdentity::color($span->serviceName),
        ], $trace->serverChain());
    }

    /**
     * Color + classification per service in the trace, for badges.
     *
     * @return array<string, array{color: string, kind: string, label: string|null}>
     */
    public static function identities(Trace $trace): array
    {
        $identities = [];

        foreach ($trace->spans as $span) {
            if ($span->serviceName === '' || isset($identities[$span->serviceName])) {
                continue;
            }

            $kind = ServiceIdentity::kind($span->serviceName, $trace->services[$span->serviceName] ?? []);

            $identities[$span->serviceName] = [
                'color' => ServiceIdentity::color($span->serviceName),
                'kind' => $kind,
                'label' => ServiceIdentity::kindLabel($kind),
            ];
        }

        return $identities;
    }
}
