<?php

declare(strict_types=1);

use Cbox\TelemetryUi\Mcp\Servers\TelemetryServer;
use Cbox\TelemetryUi\Mcp\Tools\SearchTracesTool;
use Cbox\TelemetryUi\Queries\TraceqlSpansets;
use Cbox\TelemetryUi\TelemetryUiManager;
use Illuminate\Support\Facades\Http;

/*
 * A locked viewer's raw TraceQL is scoped in EVERY spanset. Scoping only the
 * first left `{ mine } || { anything }` free to match every tenant's spans.
 */

const TRACEQL_LOCK_SCOPE = 'resource.service.name = "shop"';

it('constrains every spanset of a query', function (string $query, string $expected): void {
    expect(TraceqlSpansets::constrain($query, TRACEQL_LOCK_SCOPE))->toBe($expected);
})->with([
    'one' => ['{ span.http.route = "/a" }', '{ '.TRACEQL_LOCK_SCOPE.' && (span.http.route = "/a") }'],
    'empty' => ['{}', '{ '.TRACEQL_LOCK_SCOPE.' }'],
    'union' => ['{ status = error } || { }', '{ '.TRACEQL_LOCK_SCOPE.' && (status = error) } || { '.TRACEQL_LOCK_SCOPE.' }'],
    'structural' => ['{ name = "a" } >> { name = "b" } | select(span.x)', '{ '.TRACEQL_LOCK_SCOPE.' && (name = "a") } >> { '.TRACEQL_LOCK_SCOPE.' && (name = "b") } | select(span.x)'],
    'braces in a string' => ['{ span.q = "}{" } && { span.b = `{` }', '{ '.TRACEQL_LOCK_SCOPE.' && (span.q = "}{") } && { '.TRACEQL_LOCK_SCOPE.' && (span.b = `{`) }'],
    'escaped quote' => ['{ span.q = "a\"}" }', '{ '.TRACEQL_LOCK_SCOPE.' && (span.q = "a\"}") }'],
    'division and a slash in a string' => ['{ span.a / 2 > 1 && span.p = "//x" }', '{ '.TRACEQL_LOCK_SCOPE.' && (span.a / 2 > 1 && span.p = "//x") }'],
]);

it('refuses what it cannot read with certainty', function (string $query): void {
    expect(TraceqlSpansets::constrain($query, TRACEQL_LOCK_SCOPE))->toBeNull();
})->with([
    'escaping the group' => ['{ true ) || ( true }'],
    'unterminated string' => ['{ span.q = "open }'],
    'nested brace' => ['{ { true } }'],
    'unpaired close' => ['{ true } }'],
    'unclosed spanset' => ['{ true '],
    'no spanset' => ['status = error'],
    'a comment hiding a parenthesis' => ["{ x // (\n ) || ( true // )\n }"],
    'a block comment' => ['{ x /* ( */ }'],
    'a hash comment' => ['{ x # ( \n }'],
]);

it('scopes every spanset of a locked viewer\'s ?q=', function (): void {
    app(TelemetryUiManager::class)->restrictScopeUsing(fn ($user): array => ['services' => ['shop']]);
    Http::fake(['*' => Http::response(['traces' => []])]);

    $this->getJson(panelUrl('trace-search', ['q' => '{ span.a = "1" } || { span.b = "2" }']))->assertOk();

    expect(sentTraceql()[0] ?? '')->toStartWith('{ '.TRACEQL_LOCK_SCOPE.' && (span.a = "1") } || { '.TRACEQL_LOCK_SCOPE.' && (span.b = "2") }');
});

it('replaces an unreadable query with one that matches nothing', function (): void {
    app(TelemetryUiManager::class)->restrictScopeUsing(fn ($user): array => ['services' => ['shop']]);
    Http::fake(['*' => Http::response(['traces' => []])]);

    $this->getJson(panelUrl('trace-search', ['q' => '{ true ) || ( true }']))->assertOk();

    expect(sentTraceql()[0] ?? '')->toStartWith('{ resource.service.name = "__telemetry_ui_no_scope__" }');
});

it('scopes every spanset of search_traces under a lock', function (): void {
    app(TelemetryUiManager::class)->restrictScopeUsing(fn ($user): array => ['services' => ['shop']]);
    Http::fake(['*' => Http::response(['traces' => []])]);

    TelemetryServer::tool(SearchTracesTool::class, ['query' => '{ } || { status = error }'])->assertOk();

    expect(sentTraceql())->toBe(['{ '.TRACEQL_LOCK_SCOPE.' } || { '.TRACEQL_LOCK_SCOPE.' && (status = error) }']);
});

it('leaves a raw query alone without a lock', function (): void {
    Http::fake(['*' => Http::response(['traces' => []])]);

    TelemetryServer::tool(SearchTracesTool::class, ['query' => '{ } || { status = error }'])->assertOk();

    expect(sentTraceql())->toBe(['{ } || { status = error }']);
});
