<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Analysis;

use Cbox\TelemetryUi\Connectors\ConnectionManager;
use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Explore\TraceExceptions;
use Cbox\TelemetryUi\Queries\Ir\TraceCondition;
use Cbox\TelemetryUi\Queries\Ir\TraceOp;
use Cbox\TelemetryUi\Queries\Ir\TraceQuery;
use DateTimeImmutable;

/**
 * Reads a trace (and the exception records that name it) and diagnoses it
 * ({@see TraceDiagnosis}); resolves a `X-Telemetry-Label` label to the latest
 * trace carrying it, so "before" and "after" can be compared by name.
 */
final readonly class TraceDiagnostics
{
    /** Root-span attribute laravel-telemetry fills from the `X-Telemetry-Label` request header. */
    public const LABEL_ATTRIBUTE = 'laravel.trace.label';

    public function __construct(
        private ConnectionManager $connections,
        private TraceExceptions $exceptions,
    ) {}

    /**
     * @throws SourceException when the trace store cannot answer
     */
    public function diagnose(string $traceId): TraceDiagnosis
    {
        $trace = $this->connections->traces()->trace($traceId);

        return TraceDiagnosis::analyze($trace, $this->exceptions->forTrace($trace), $this->thresholds());
    }

    /**
     * The newest trace whose root carries the label, within the window. From
     * laravel-telemetry 3.2 the label is on every span of the request and of
     * the jobs it dispatches; asking for roots keeps one hit per trace and
     * names the trace by its entry point.
     *
     * @throws SourceException
     */
    public function latestWithLabel(string $label, DateTimeImmutable $start, DateTimeImmutable $end): ?string
    {
        $latest = null;

        foreach ($this->connections->traces()->search(
            new TraceQuery([
                TraceCondition::token('nestedSetParent', TraceOp::Lt, '0'),
                TraceCondition::eq('span.'.self::LABEL_ATTRIBUTE, $label),
            ]),
            $start,
            $end,
            limit: 50,
        ) as $summary) {
            if ($latest === null || $summary->startedAt > $latest->startedAt) {
                $latest = $summary;
            }
        }

        return $latest?->traceId;
    }

    /**
     * @return array{slow_query_ms: float, overhead_ms: float, overhead_ratio: float, repeat_threshold: int}
     */
    public function thresholds(): array
    {
        $config = config('telemetry-ui.traces.diagnostics', []);

        return TraceDiagnosis::thresholds(array_filter(
            is_array($config) ? $config : [],
            static fn (mixed $value): bool => is_int($value) || is_float($value),
        ));
    }
}
