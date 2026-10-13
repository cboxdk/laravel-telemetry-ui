<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Connectors;

use RuntimeException;

/**
 * A backend read failed. The exception carries two messages: a generic,
 * user-safe {@see getMessage()} that cards render into the dashboard (the gate
 * can be opened to semi-trusted operators, so it must not leak the backend URL,
 * query string or response body), and a full {@see $detail} for server-side
 * logs. {@see ApiClient} logs the detail at the throw site.
 */
class SourceException extends RuntimeException
{
    private function __construct(
        string $message,
        public readonly string $detail,
        public readonly BackendStatus $status = BackendStatus::Error,
        /** The backend's HTTP status, when it answered with one. */
        public readonly ?int $httpStatus = null,
    ) {
        parent::__construct($message);
    }

    /**
     * A failure whose message a driver has already made user-safe (no backend
     * URL or response body) — e.g. a missing config value or a backend error
     * string that is meaningful to show.
     */
    public static function because(string $message, BackendStatus $status = BackendStatus::Error): self
    {
        return new self($message, $message, $status);
    }

    public static function connectionFailed(string $url, string $reason, ?float $timeout = null): self
    {
        $status = BackendStatus::classifyConnectionFailure($reason);

        return new self(
            match ($status) {
                BackendStatus::Tls => 'The telemetry backend\'s TLS certificate could not be verified.',
                // Said as what it is: "could not reach" sends people to check
                // a hostname that is fine, when the query was simply too big
                // for the time it was given.
                BackendStatus::Timeout => 'The telemetry backend did not answer'
                    .($timeout !== null ? ' within '.rtrim(rtrim(number_format($timeout, 1, '.', ''), '0'), '.').'s' : ' in time')
                    .'. A long window over a lot of data can take longer: try a shorter window, or raise the connection\'s timeout.',
                BackendStatus::Dropped => 'The telemetry backend cut its answer off part-way. Usually a proxy in front of it (a response buffer or size limit), or the backend giving up on a heavy query: try a shorter window.',
                default => 'Could not reach the telemetry backend.',
            },
            "Could not reach [{$url}]: {$reason}",
            $status,
        );
    }

    public static function requestFailed(string $url, int $status, string $body): self
    {
        return new self(
            ($reason = self::backendReason($body)) === ''
                ? "The telemetry backend returned status {$status}."
                : "The telemetry backend refused the query (status {$status}): {$reason}",
            "Request to [{$url}] failed with status {$status}: ".mb_substr($body, 0, 1000),
            BackendStatus::classifyHttpStatus($status),
            $status,
        );
    }

    /**
     * The reason a Prometheus-API backend (Prometheus, Mimir, Loki, Tempo's
     * metrics) gave for refusing a query — `{"status":"error","error":"…"}`.
     * It is the backend's own sentence about the query ("query processing
     * would load too many samples into memory"), which is what makes a 422
     * fixable, so it is shown; an arbitrary body (an HTML error page, a
     * proxy's text) is not.
     */
    private static function backendReason(string $body): string
    {
        $decoded = json_decode($body, true);

        if (! is_array($decoded) || ($decoded['status'] ?? null) !== 'error' || ! is_string($decoded['error'] ?? null)) {
            return '';
        }

        $reason = trim(preg_replace('#https?://\S+#', '', $decoded['error']) ?? '');

        return $reason === '' ? '' : mb_strimwidth($reason, 0, 300, '…');
    }

    public static function unexpectedPayload(string $url, string $reason): self
    {
        // The reason is a parser/validation description or a backend error
        // string (e.g. a PromQL "parse error", a Linear GraphQL message) — safe
        // to surface. The backend URL is not, so it stays in the detail only.
        return new self(
            "Unexpected response from the telemetry backend: {$reason}",
            "Unexpected payload from [{$url}]: {$reason}",
            BackendStatus::UnexpectedApi,
        );
    }
}
