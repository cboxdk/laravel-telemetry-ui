<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Http\Api;

use Cbox\TelemetryUi\Analysis\Snapshot\MarkdownSnapshot;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * A Markdown snapshot as an API response: JSON by default (`markdown`,
 * `bytes`, `truncated`, `omitted`, plus the caller's identifying keys), or
 * the Markdown itself as `text/markdown` with `?format=text` — for `curl … |
 * pbcopy` or an agent that reads URLs.
 */
final class Markdown
{
    /**
     * @param  array<string, mixed>  $identity
     */
    public static function respond(Request $request, MarkdownSnapshot $snapshot, array $identity = []): JsonResponse|Response
    {
        if (in_array($request->query('format'), ['text', 'md', 'markdown'], true)) {
            return new Response($snapshot->markdown, 200, [
                'Content-Type' => 'text/markdown; charset=UTF-8',
                'Cache-Control' => 'no-store',
                'X-Content-Type-Options' => 'nosniff',
            ]);
        }

        return Json::ok([...$identity, ...$snapshot->toArray()]);
    }
}
