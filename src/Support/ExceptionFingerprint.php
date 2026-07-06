<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Support;

/**
 * The Sentry-style error fingerprint, mirroring
 * cboxdk/laravel-telemetry's ExceptionAttributes::fingerprint():
 * sha256("{class}@{basename(file)}:{line}") truncated to 12 hex chars.
 *
 * The backend stamps this on its exception records at report() time; browser
 * exception spans arrive WITHOUT one (the JS SDK only ships type/message/
 * file/line), so the UI computes the same fingerprint read-side to group
 * frontend errors identically.
 */
final class ExceptionFingerprint
{
    public static function compute(string $type, string $file, int $line): string
    {
        return substr(hash('sha256', $type.'@'.basename($file).':'.$line), 0, 12);
    }
}
