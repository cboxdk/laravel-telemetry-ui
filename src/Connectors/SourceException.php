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
final class SourceException extends RuntimeException
{
    private function __construct(string $message, public readonly string $detail)
    {
        parent::__construct($message);
    }

    /**
     * A failure whose message a driver has already made user-safe (no backend
     * URL or response body) — e.g. a missing config value or a backend error
     * string that is meaningful to show.
     */
    public static function because(string $message): self
    {
        return new self($message, $message);
    }

    public static function connectionFailed(string $url, string $reason): self
    {
        return new self(
            'Could not reach the telemetry backend.',
            "Could not reach [{$url}]: {$reason}",
        );
    }

    public static function requestFailed(string $url, int $status, string $body): self
    {
        return new self(
            "The telemetry backend returned status {$status}.",
            "Request to [{$url}] failed with status {$status}: ".mb_substr($body, 0, 1000),
        );
    }

    public static function unexpectedPayload(string $url, string $reason): self
    {
        // The reason is a parser/validation description or a backend error
        // string (e.g. a PromQL "parse error", a Linear GraphQL message) — safe
        // to surface. The backend URL is not, so it stays in the detail only.
        return new self(
            "Unexpected response from the telemetry backend: {$reason}",
            "Unexpected payload from [{$url}]: {$reason}",
        );
    }
}
