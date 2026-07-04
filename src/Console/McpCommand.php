<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Console;

use Cbox\TelemetryUi\Mcp\McpServer;
use Illuminate\Console\Command;

/**
 * Serve the telemetry stack over the Model Context Protocol (stdio), so an
 * agent — Claude Desktop, Cursor, your own — can query metrics, traces, logs
 * and the correlation/analysis tools directly. Wire it as an MCP server:
 *
 *     { "command": "php", "args": ["artisan", "telemetry-ui:mcp"] }
 *
 * Read-only: every tool goes through the same read drivers the dashboard uses.
 */
final class McpCommand extends Command
{
    /** @var string */
    protected $signature = 'telemetry-ui:mcp';

    /** @var string */
    protected $description = 'Serve metrics/traces/logs + analysis tools over MCP (stdio).';

    public function handle(McpServer $server): int
    {
        $in = fopen('php://stdin', 'rb');
        $out = fopen('php://stdout', 'wb');

        if ($in === false || $out === false) {
            $this->error('Could not open stdio streams.');

            return self::FAILURE;
        }

        while (($line = fgets($in)) !== false) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $message = json_decode($line, true);
            if (! is_array($message)) {
                continue; // ignore malformed frames rather than crash the session
            }

            /** @var array<string, mixed> $message */
            $response = $server->handle($message);

            if ($response !== null) {
                fwrite($out, json_encode($response, JSON_UNESCAPED_SLASHES).PHP_EOL);
                fflush($out);
            }
        }

        return self::SUCCESS;
    }
}
