<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Queries\Ir;

/**
 * A LogQL line-filter operator (matches against the raw log line, not a
 * label). The string value is the LogQL spelling.
 */
enum LineOp: string
{
    case Contains = '|=';
    case NotContains = '!=';
    case Regex = '|~';
    case NotRegex = '!~';
}
