<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;

/**
 * @param  list<array{0: string, 1: string, 2: array<string, string>}>  $values
 * @return array<string, mixed>
 */
function contentAuditStream(array $values): array
{
    return lokiStreams([['stream' => ['service_name' => 'cbox-web'], 'values' => $values]]);
}

/**
 * The records cboxdk/statamic-telemetry 3.1 writes, as Loki returns them:
 * the event name as the line, the attributes as structured metadata with
 * dots turned into underscores.
 */
function fakeContentChanges(): void
{
    Http::fake(['loki.test:3100/loki/api/v1/query_range*' => Http::response(contentAuditStream([
        ['1735689600000000000', 'statamic.content.change', [
            'statamic_content_type' => 'entry', 'statamic_content_action' => 'updated',
            'statamic_content_id' => 'e1', 'statamic_content_title' => 'Pricing', 'statamic_collection' => 'pages',
            'statamic_site' => 'default', 'statamic_content_changed' => 'title, price_table',
            'statamic_content_edit_url' => 'https://example.test/cp/collections/pages/entries/e1',
            'statamic_content_origin' => 'cp', 'user_id' => '7', 'user_name' => 'Maja Holm', 'trace_id' => 'abc123',
        ]],
        ['1735689660000000000', 'statamic.content.change', [
            'statamic_content_type' => 'entry', 'statamic_content_action' => 'published',
            'statamic_content_id' => 'e2', 'statamic_content_title' => 'Spring launch', 'statamic_collection' => 'blog',
            'statamic_content_origin' => 'cp', 'user_id' => '8',
        ]],
        ['1735689720000000000', 'statamic.content.change', [
            'statamic_content_type' => 'asset', 'statamic_content_action' => 'deleted',
            'statamic_content_id' => 'assets::old.jpg', 'statamic_content_title' => 'old.jpg', 'statamic_asset_container' => 'assets',
            'statamic_content_origin' => 'console', 'process_command' => 'assets:clean',
            // Not ours to render as a link: only http(s) survives.
            'statamic_content_edit_url' => 'javascript:alert(1)',
        ]],
        // An incidental match on the line filter is not a change.
        ['1735689780000000000', 'something mentioning statamic.content.change in passing', []],
    ]))]);
}

it('lists content changes newest first: who, what, what it did', function (): void {
    fakeContentChanges();

    $this->getJson(panelUrl('content-audit-log', ['period' => '1h']))
        ->assertOk()
        ->assertJsonPath('kind', 'table')
        ->assertJsonCount(3, 'rows')
        ->assertJsonPath('rows.0.action.v', 'deleted')
        ->assertJsonPath('rows.0.who.v', 'console')
        ->assertJsonPath('rows.0.who.sub', 'assets:clean')
        ->assertJsonPath('rows.1.item.v', 'Spring launch')
        // No name on the record: the id, resolvable to a name by the host.
        ->assertJsonPath('rows.1.who.v', '8')
        ->assertJsonPath('rows.1.who.dim', ['key' => 'user.id', 'value' => '8'])
        ->assertJsonPath('rows.2.item.v', 'Pricing')
        ->assertJsonPath('rows.2.item.sub', 'entry · pages · default')
        ->assertJsonPath('rows.2.item.link', ['to' => 'url', 'href' => 'https://example.test/cp/collections/pages/entries/e1'])
        ->assertJsonPath('rows.2.changed.v', 'title, price_table')
        ->assertJsonPath('rows.2.who.v', 'Maja Holm')
        ->assertJsonPath('rows.2._link.to', 'trace')
        ->assertJsonPath('rows.2._link.id', 'abc123');
});

it('never turns a non-web edit url into a link', function (): void {
    fakeContentChanges();

    $row = $this->getJson(panelUrl('content-audit-log', ['period' => '1h']))->json('rows.0');

    expect($row['item'])->not->toHaveKey('link');
});

it('filters by type, action and free text, and offers what it saw as options', function (): void {
    fakeContentChanges();

    $this->getJson(panelUrl('content-audit-log', ['period' => '1h', 'audit_type' => 'entry']))
        ->assertJsonCount(2, 'rows')
        ->assertJsonPath('controls.0.options.*.value', ['', 'asset', 'entry']);

    $this->getJson(panelUrl('content-audit-log', ['period' => '1h', 'audit_action' => 'published']))
        ->assertJsonCount(1, 'rows')
        ->assertJsonPath('rows.0.item.v', 'Spring launch');

    $this->getJson(panelUrl('content-audit-log', ['period' => '1h', 'audit_q' => 'price_table']))
        ->assertJsonCount(1, 'rows')
        ->assertJsonPath('rows.0.item.v', 'Pricing');
});

it('says what it needs when there are no records', function (): void {
    Http::fake(['loki.test:3100/*' => Http::response(contentAuditStream([]))]);

    expect($this->getJson(panelUrl('content-audit-log', ['period' => '1h']))->json('empty'))
        ->toContain('statamic-telemetry 3.1');
});

it('reports a backend failure instead of throwing', function (): void {
    Http::fake(['loki.test:3100/*' => Http::response('boom', 500)]);

    $this->getJson(panelUrl('content-audit-log', ['period' => '1h']))
        ->assertOk()
        ->assertJsonPath('rows', [])
        ->assertJsonStructure(['error']);
});
