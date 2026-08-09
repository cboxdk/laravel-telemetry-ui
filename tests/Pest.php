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
