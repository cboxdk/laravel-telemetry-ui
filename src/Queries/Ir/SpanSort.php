<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Queries\Ir;

/**
 * How a {@see SpanAggregation} ranks its groups.
 */
enum SpanSort: string
{
    case Total = 'total';
    case Avg = 'avg';
    case P95 = 'p95';
    case Max = 'max';
    case Calls = 'calls';
}
