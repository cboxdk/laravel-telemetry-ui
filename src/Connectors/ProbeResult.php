<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Connectors;

/**
 * The outcome of a connection probe: is this backend reachable, authenticated,
 * and actually the API we think it is?
 *
 * Carries no backend URL or response body — a probe result is rendered in the
 * same semi-trusted dashboard as a card error, so it follows the same
 * disclosure rule as {@see SourceException::getMessage()}.
 */
readonly class ProbeResult
{
    public function __construct(
        public BackendStatus $status,
        public string $message,
        public ?string $version = null,
    ) {}

    public static function pass(?string $version = null): self
    {
        return new self(
            BackendStatus::Ok,
            $version === null ? 'Connected.' : "Connected (version {$version}).",
            $version,
        );
    }

    public static function fail(BackendStatus $status, string $message): self
    {
        return new self($status, $message);
    }

    /**
     * Build a result from a failed backend read, reusing the exception's own
     * classification and user-safe message.
     */
    public static function fromException(SourceException $exception): self
    {
        return new self($exception->status, $exception->getMessage());
    }

    public function passed(): bool
    {
        return $this->status->isOk();
    }
}
