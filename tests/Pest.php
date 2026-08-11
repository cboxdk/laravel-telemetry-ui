<?php

declare(strict_types=1);

use Cbox\Telemetry\Support\ExportReport;
use Cbox\TelemetryUi\Support\AnnotationWriter;
use Cbox\TelemetryUi\Tests\DisabledTestCase;
use Cbox\TelemetryUi\Tests\TestCase;
use Illuminate\Http\Client\Request;
use Mockery\MockInterface;

pest()->extend(TestCase::class)->in('Feature');
pest()->extend(DisabledTestCase::class)->in('Disabled');

/**
 * Expect one flush() on a mocked emitter, stubbed to match whichever signature
 * the installed cboxdk/laravel-telemetry actually has.
 *
 * The package requires ^1.0, and the emitter changed shape inside that range:
 * up to 1.1 `flush()` returned void, from 1.2 it returns a **final**
 * ExportReport. Mockery cannot synthesise a return value for a final class, so
 * a bare `shouldReceive('flush')` throws on 1.2+ — and that exception lands in
 * {@see AnnotationWriter::write()}'s deliberate catch-all (a transport failure
 * must never abort the annotate command or the scan-versions cron), which
 * swallows it and reports the annotation as *not* emitted. The test then fails
 * somewhere far away, or worse, passes while proving nothing.
 *
 * So the stub is derived from the installed signature rather than hardcoded,
 * and the instance is built without its constructor: nothing here reads the
 * report, and its shape is the emitter's business, not this package's.
 */
function stubTelemetryFlush(MockInterface $telemetry): void
{
    // A real, empty report: no exporter was attempted, so nothing was lost.
    // It used to be built with newInstanceWithoutConstructor() to dodge the
    // signature difference across ^1.0 — which was fine only while nobody
    // read it. AnnotationWriter reads it now, and an uninitialised readonly
    // property throws the moment it does.
    $telemetry->shouldReceive('flush')->once()->andReturn(new ExportReport);
}

/**
 * One metric name per detectable family in the built-in page registry, so a
 * fake backend holding this list lights up every page that declares a `detect`
 * pattern. Page detection reads the metric-name index in one call, so a fake
 * that answers /api/v1/label/__name__/values with something else (a service
 * name, an empty list) silently 404s those pages.
 *
 * @return list<string>
 */
function allMetricNames(): array
{
    return [
        'queue_metrics_pending_jobs',
        'queue_autoscale_desired_workers',
        'horizon_jobs_total',
        'commands_runs_total',
        'cache_operations_total',
        'storage_operations_total',
        'livewire_updates_total',
        'feature_checks_total',
        'reverb_connections_active',
        'system_cpu_seconds_total',
        'statamic_static_cache_hits_total',
        'statamic_stache_warm_seconds',
        'statamic_glide_generations_total',
        'statamic_forms_submissions_total',
        'statamic_content_changes_total',
        'statamic_entries_count',
    ];
}

/**
 * Query-string parameters of a faked client request (GET data lives in the
 * URL, not in Request::data()).
 *
 * @return array<string, mixed>
 */
function requestQuery(Request $request): array
{
    parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

    /** @var array<string, mixed> $query */
    return $query;
}
