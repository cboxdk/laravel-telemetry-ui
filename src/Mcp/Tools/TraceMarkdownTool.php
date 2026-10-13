<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Mcp\Tools;

use Cbox\TelemetryUi\Analysis\TraceDiagnostics;
use Cbox\TelemetryUi\Connectors\SourceException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\JsonSchema as JsonSchemaFactory;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;

/**
 * One trace as a bounded Markdown snapshot — the dashboard's "Copy as
 * Markdown" — for an agent to quote in a pull request, an issue or a hand-off
 * note. A tool of its own rather than a format on `trace_summary`: that one
 * is paged, structured data to reason over; this is one finished document to
 * pass on, and an agent picks it by name.
 */
final class TraceMarkdownTool extends TelemetryTool
{
    protected string $name = 'trace_markdown';

    protected string $description = 'One trace as a ready-to-paste Markdown snapshot (≤12 KB): title (root, service, status, duration, label, trace id), flags (errors first), time per category, totals, the exceptions with their app frames and source excerpt, the spans that led up to the first failure, and the N+1/duplicate/slow query groups with SQL and origin file:line. Use it to write a PR description, an issue or a hand-off; use trace_summary to reason over the data. SQL is as the app recorded it, never with bindings.';

    public function __construct(private readonly TraceDiagnostics $diagnostics) {}

    /**
     * @return array<string, JsonSchemaFactory>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'trace_id' => JsonSchemaFactory::string()->description('The trace id (hex)')->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $traceId = trim((string) $request->get('trace_id', ''));

        if ($traceId === '') {
            return Response::error('trace_id is required.');
        }

        try {
            // Inside the viewer's scope lock, as the API's /markdown is.
            $trace = $this->diagnostics->visible($traceId);
        } catch (SourceException $exception) {
            return Response::error($exception->getMessage());
        }

        if ($trace === null) {
            return Response::error("Trace {$traceId} has no spans (not found, or outside the trace store's retention).");
        }

        // Stacktraces and source only for a viewer who may read exceptions.
        return Response::text($this->diagnostics->snapshot($trace, $this->mayReadExceptions())->markdown);
    }
}
