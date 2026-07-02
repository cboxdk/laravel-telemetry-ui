<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Http\Controllers;

use Cbox\TelemetryUi\Connectors\ConnectionManager;
use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Queries\Results\Span;
use Cbox\TelemetryUi\Queries\Results\Trace;
use Cbox\TelemetryUi\Support\Fleet;
use Cbox\TelemetryUi\Support\SchemaDetector;
use Cbox\TelemetryUi\Support\ServiceIdentity;
use Cbox\TelemetryUi\TelemetryUiManager;
use Illuminate\Contracts\View\View;

final class TraceController
{
    public function __invoke(
        TelemetryUiManager $manager,
        SchemaDetector $detector,
        Fleet $fleet,
        ConnectionManager $connections,
        string $traceId,
    ): View {
        $trace = null;
        $error = null;

        try {
            $trace = $connections->traces()->trace($traceId);
        } catch (SourceException $exception) {
            $error = $exception->getMessage();
        }

        /** @var view-string $view */
        $view = 'telemetry-ui::trace';

        return view($view, [
            'pages' => $manager->visiblePages($detector),
            'services' => $fleet->services(),
            'environments' => $fleet->environments(),
            'traceId' => $traceId,
            'trace' => $trace,
            'error' => $error,
            'rows' => $trace !== null ? $this->waterfall($trace) : [],
            'chain' => $trace !== null ? $this->chain($trace) : [],
            'identities' => $trace !== null ? $this->identities($trace) : [],
        ]);
    }

    /**
     * Flatten the span tree depth-first into waterfall rows with layout
     * offsets relative to the trace's own time window, plus the data the
     * view needs for collapsible nesting (ancestor ids, child counts).
     *
     * @return list<array{span: Span, depth: int, offsetPct: float, widthPct: float, ancestors: list<string>, children: int}>
     */
    private function waterfall(Trace $trace): array
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

        $children = [];
        $roots = [];

        foreach ($trace->spans as $span) {
            // Orphans (parent not in the trace, e.g. dropped by tail
            // sampling) surface as top-level rows instead of vanishing.
            if ($span->parentSpanId !== null && isset($spanIds[$span->parentSpanId])) {
                $children[$span->parentSpanId][] = $span;
            } else {
                $roots[] = $span;
            }
        }

        $rows = [];

        // Depth-first preorder via an explicit stack: children are pushed
        // in reverse so they pop in original (start-time) order.
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
     * The request chain through the infrastructure (server spans in start
     * order): edge proxy → reverse proxy → app → downstream app.
     *
     * @return list<array{span: Span, kind: string, color: string}>
     */
    private function chain(Trace $trace): array
    {
        return array_map(fn (Span $span): array => [
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
    private function identities(Trace $trace): array
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
