<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Connectors;

use RuntimeException;

final class SourceException extends RuntimeException
{
    public static function connectionFailed(string $url, string $reason): self
    {
        return new self("Could not reach [{$url}]: {$reason}");
    }

    public static function requestFailed(string $url, int $status, string $body): self
    {
        $body = mb_substr($body, 0, 500);

        return new self("Request to [{$url}] failed with status {$status}: {$body}");
    }

    public static function unexpectedPayload(string $url, string $reason): self
    {
        return new self("Unexpected payload from [{$url}]: {$reason}");
    }
}
