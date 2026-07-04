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
    /**
     * @return array{0: DateTimeImmutable, 1: DateTimeImmutable}
     */
    protected function window(Request $request): array
    {
        $minutes = max(1, (int) $request->get('minutes', 60));

        return [new DateTimeImmutable('-'.$minutes.' minutes'), new DateTimeImmutable];
    }
}
