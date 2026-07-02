<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Http\Controllers;

use Cbox\TelemetryUi\Connectors\ConnectionManager;
use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Queries\Results\Span;
use Cbox\TelemetryUi\Queries\Results\Trace;
use Cbox\TelemetryUi\Support\Fleet;
use Cbox\TelemetryUi\Support\SchemaDetector;
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
        ]);
    }

    /**
     * Flatten the span tree depth-first into waterfall rows with layout
     * offsets relative to the trace's own time window.
     *
     * @return list<array{span: Span, depth: int, offsetPct: float, widthPct: float}>
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

        $walk = function (Span $span, int $depth) use (&$walk, &$rows, $children, $traceStart, $window): void {
            $rows[] = [
                'span' => $span,
                'depth' => $depth,
                'offsetPct' => (($span->startNano - $traceStart) / $window) * 100,
                'widthPct' => max(0.35, (($span->endNano - $span->startNano) / $window) * 100),
            ];

            foreach ($children[$span->spanId] ?? [] as $child) {
                $walk($child, $depth + 1);
            }
        };

        foreach ($roots as $root) {
            $walk($root, 0);
        }

        return $rows;
    }
}
