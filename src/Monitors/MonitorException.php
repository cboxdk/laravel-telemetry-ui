<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Monitors;

use RuntimeException;

/**
 * A monitor that could not be read, placed or removed. `kind` is the API
 * error type it answers as: `forbidden` (the viewer's scope does not allow
 * it), `invalid` (the request, or the monitored app refused it), `not_found`
 * or `backend` (the app could not be reached).
 */
final class MonitorException extends RuntimeException
{
    public function __construct(string $message, public readonly string $kind = 'backend', ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    public static function forbidden(string $message): self
    {
        return new self($message, 'forbidden');
    }

    public static function invalid(string $message): self
    {
        return new self($message, 'invalid');
    }

    public static function notFound(string $message): self
    {
        return new self($message, 'not_found');
    }
}
