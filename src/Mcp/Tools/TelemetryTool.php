<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Mcp\Tools;

use DateTimeImmutable;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Tool;

/**
 * Shared base for the telemetry MCP tools — read-only windows over the same
 * drivers the dashboard uses.
 */
abstract class TelemetryTool extends Tool
{
    /** Hard ceilings so an agent can't drive an unbounded/expensive query. */
    protected const MAX_MINUTES = 10_080; // 7 days

    protected const MAX_LIMIT = 500;

    /**
     * @return array{0: DateTimeImmutable, 1: DateTimeImmutable}
     */
    protected function window(Request $request): array
    {
        $raw = $request->get('minutes', 60);
        $minutes = min(self::MAX_MINUTES, max(1, is_numeric($raw) ? (int) $raw : 60));

        return [new DateTimeImmutable('-'.$minutes.' minutes'), new DateTimeImmutable];
    }

    protected function limit(Request $request, int $default = 20): int
    {
        $raw = $request->get('limit', $default);

        return min(self::MAX_LIMIT, max(1, is_numeric($raw) ? (int) $raw : $default));
    }
}
