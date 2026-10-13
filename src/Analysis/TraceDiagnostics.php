<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Analysis;

use Cbox\TelemetryUi\Analysis\Snapshot\MarkdownSnapshot;
use Cbox\TelemetryUi\Analysis\Snapshot\TraceSnapshot;
use Cbox\TelemetryUi\Connectors\ConnectionManager;
use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Explore\TraceExceptions;
use Cbox\TelemetryUi\Http\Api\RequestScope;
use Cbox\TelemetryUi\Queries\Ir\TraceCondition;
use Cbox\TelemetryUi\Queries\Results\Trace;
use Cbox\TelemetryUi\Support\ScopeLock;
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
        // A trace outside the viewer's scope lock diagnoses as one with no
        // spans — "not found", exactly as the API answers it.
        $trace = $this->visible($traceId) ?? new Trace($traceId, []);

        return TraceDiagnosis::analyze($trace, $trace->spans === [] ? [] : $this->exceptions->forTrace($trace), $this->thresholds());
    }

    /**
     * The trace, when it exists and the viewer's scope lock allows all of it
     * ({@see ScopeLock::allowsTrace()}); null otherwise. Every read that
     * takes a trace id from outside goes through here.
     *
     * @throws SourceException when the trace store cannot answer
     */
    public function visible(string $traceId): ?Trace
    {
        $trace = $this->connections->traces()->trace($traceId);

        return $trace->spans !== [] && app(ScopeLock::class)->allowsTrace($trace) ? $trace : null;
    }

    /**
     * The trace as a bounded Markdown snapshot ({@see TraceSnapshot}). Takes
     * the trace already read, so a caller that has to check it first (the
     * API's scope lock) reads it once. Without `$exceptionDetail` the
     * exceptions keep their headline (type, message, file:line, group) but
     * not the stacktrace or source excerpt — for a viewer who may read traces
     * but not the exceptions page.
     */
    public function snapshot(Trace $trace, bool $exceptionDetail = true): MarkdownSnapshot
    {
        $exceptions = array_map(static fn (array $e): array => $exceptionDetail ? $e : [...$e, 'stacktrace' => '', 'code' => ''], $this->exceptions->detailed($trace));

        $diagnosis = TraceDiagnosis::analyze($trace, array_map(static function (array $e): array {
            unset($e['stacktrace'], $e['code']);

            return $e;
        }, $exceptions), $this->thresholds());

        return TraceSnapshot::build($trace, $diagnosis, $exceptions);
    }

    /**
     * The newest trace whose root carries the label, within the window. From
     * laravel-telemetry 3.5 the label is on every span of the request and of
     * the jobs it dispatches; asking for roots keeps one hit per trace and
     * names the trace by its entry point.
     *
     * @throws SourceException
     */
    public function latestWithLabel(string $label, DateTimeImmutable $start, DateTimeImmutable $end): ?string
    {
        $latest = null;

        // Inside the viewer's lock, like every other search: a label is not a
        // way round it.
        $summaries = RootSearch::search(
            $this->connections->traces(),
            [...(new RequestScope)->scopeTraceConditions(), TraceCondition::eq('span.'.self::LABEL_ATTRIBUTE, $label)],
            $start,
            $end,
            limit: 50,
        );

        foreach ($summaries as $summary) {
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
