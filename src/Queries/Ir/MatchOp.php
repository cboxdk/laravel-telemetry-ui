<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Queries\Ir;

/**
 * A label/attribute match operator, shared by every dialect's IR. The string
 * value is the PromQL/LogQL spelling; TraceQL uses the same tokens (spaced by
 * its compiler).
 */
enum MatchOp: string
{
    case Eq = '=';
    case Neq = '!=';
    case Re = '=~';
    case Nre = '!~';
}
