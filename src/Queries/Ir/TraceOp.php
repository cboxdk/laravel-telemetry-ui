<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Queries\Ir;

/**
 * A TraceQL comparison operator. Covers both the equality/regex family and the
 * numeric/duration comparisons the trace cards use. The string value is the
 * TraceQL spelling (the compiler surrounds it with spaces).
 */
enum TraceOp: string
{
    case Eq = '=';
    case Neq = '!=';
    case Re = '=~';
    case Nre = '!~';
    case Gt = '>';
    case Gte = '>=';
    case Lt = '<';
    case Lte = '<=';
}
