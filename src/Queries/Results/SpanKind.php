<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Queries\Results;

enum SpanKind: string
{
    case Unspecified = 'unspecified';
    case Internal = 'internal';
    case Server = 'server';
    case Client = 'client';
    case Producer = 'producer';
    case Consumer = 'consumer';

    /**
     * OTLP JSON encodes the kind either as a protobuf enum name or an integer.
     */
    public static function fromOtlp(int|string|null $kind): self
    {
        return match ($kind) {
            1, 'SPAN_KIND_INTERNAL' => self::Internal,
            2, 'SPAN_KIND_SERVER' => self::Server,
            3, 'SPAN_KIND_CLIENT' => self::Client,
            4, 'SPAN_KIND_PRODUCER' => self::Producer,
            5, 'SPAN_KIND_CONSUMER' => self::Consumer,
            default => self::Unspecified,
        };
    }
}
