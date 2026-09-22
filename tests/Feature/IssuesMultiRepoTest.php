<?php

declare(strict_types=1);

use Cbox\TelemetryUi\Connectors\ConnectionManager;
use Cbox\TelemetryUi\Panels\Builtin\IssuesList;
use Cbox\TelemetryUi\TelemetryUiManager;
use Illuminate\Support\Facades\Http;

/**
 * The issues page is config-gated at boot, and these tests configure the
 * tracker afterwards — register it the way the provider would.
 */
function registerIssuesPage(): void
{
    app(TelemetryUiManager::class)->page('issues', 'Issues', group: null);
    app(TelemetryUiManager::class)->panel(IssuesList::class, page: 'issues');
}

function fakeTwoRepos(): void
{
    registerIssuesPage();

    config()->set('telemetry-ui.connections.issues', [
        ['driver' => 'github', 'repo' => 'cboxdk/frontend', 'token' => 'ghp_x', 'label' => 'frontend'],
        ['driver' => 'github', 'repo' => 'cboxdk/api', 'token' => 'ghp_y', 'label' => 'api'],
    ]);
    registerIssuesPage();

    Http::fake([
        'api.github.com/repos/cboxdk/frontend/issues*' => Http::response([
            ['number' => 1, 'title' => 'Button misaligned', 'state' => 'open', 'html_url' => 'https://github.com/cboxdk/frontend/issues/1', 'updated_at' => '2026-07-01T10:00:00Z', 'labels' => [['name' => 'ui']]],
        ]),
        'api.github.com/repos/cboxdk/api/issues*' => Http::response([
            ['number' => 2, 'title' => 'Timeout on /orders', 'state' => 'open', 'html_url' => 'https://github.com/cboxdk/api/issues/2', 'updated_at' => '2026-07-02T10:00:00Z', 'labels' => [['name' => 'bug']]],
        ]),
    ]);
}

it('builds one issue source per configured repo, labelled', function (): void {
    fakeTwoRepos();

    $sources = app(ConnectionManager::class)->issueSources();

    expect($sources)->toHaveCount(2)
        ->and(array_map(fn (array $s): string => $s['label'], $sources))->toBe(['frontend', 'api']);
});

it('still treats a single (non-list) issues connection as one source', function (): void {
    config()->set('telemetry-ui.connections.issues', ['driver' => 'github', 'repo' => 'cboxdk/only', 'token' => 'ghp_z']);

    expect(app(ConnectionManager::class)->issueSources())->toHaveCount(1)
        ->and(app(ConnectionManager::class)->hasIssues())->toBeTrue();
});

it('aggregates issues from every repo with a source badge and filter', function (): void {
    fakeTwoRepos();

    $this->getJson(panelUrl('issues-list'))
        ->assertOk()
        ->assertSee('Button misaligned')
        ->assertSee('Timeout on /orders')
        ->assertJsonFragment(['v' => 'frontend', 'badge' => 'frontend', 'tone' => 'info'])   // source badges
        ->assertJsonFragment(['v' => 'api', 'badge' => 'api', 'tone' => 'info'])
        ->assertJsonFragment(['value' => '', 'label' => 'All repos'])                         // the source filter appears
        // Several trackers: rows open the tracker itself (the in-app issue
        // view resolves ids against the primary tracker only).
        ->assertJsonFragment(['_link' => ['to' => 'url', 'href' => 'https://github.com/cboxdk/api/issues/2']])
        // Label chips filter the panel.
        ->assertJsonFragment(['link' => ['to' => 'param', 'params' => ['issue_label' => 'bug']]]);

    // Filtering to one repo drops the other.
    $this->getJson(panelUrl('issues-list', ['issue_source' => 'api']))
        ->assertOk()
        ->assertSee('Timeout on /orders')
        ->assertDontSee('Button misaligned');
});

it('opens issues in-app when a single tracker is configured', function (): void {
    config()->set('telemetry-ui.connections.issues', ['driver' => 'github', 'repo' => 'cboxdk/api', 'token' => 'ghp_y']);
    registerIssuesPage();

    Http::fake([
        'api.github.com/repos/cboxdk/api/issues*' => Http::response([
            ['number' => 2, 'title' => 'Timeout on /orders', 'state' => 'open', 'html_url' => 'https://github.com/cboxdk/api/issues/2', 'updated_at' => '2026-07-02T10:00:00Z', 'labels' => [['name' => 'bug']]],
        ]),
    ]);

    $this->getJson(panelUrl('issues-list', ['issue_label' => 'bug']))
        ->assertOk()
        ->assertJsonPath('rows.0._link', ['to' => 'issue', 'id' => '#2'])
        ->assertJsonPath('drill', ['to' => 'url', 'href' => 'https://github.com/cboxdk/api'])
        ->assertJsonFragment(['param' => 'issue_label', 'label' => 'Label', 'type' => 'select', 'value' => 'bug', 'options' => [['value' => '', 'label' => 'All labels'], ['value' => 'bug', 'label' => 'bug']]]);
});

it('skips a misconfigured repo in a list instead of 500ing the page', function (): void {
    config()->set('telemetry-ui.connections.issues', [
        ['driver' => 'github', 'repo' => 'no-slash', 'label' => 'broken'], // invalid owner/name → build throws
        ['driver' => 'github', 'repo' => 'cboxdk/api', 'token' => 'ghp_y', 'label' => 'api'],
    ]);
    registerIssuesPage();

    Http::fake([
        'api.github.com/repos/cboxdk/api/issues*' => Http::response([
            ['number' => 2, 'title' => 'Timeout on /orders', 'state' => 'open', 'html_url' => 'https://github.com/cboxdk/api/issues/2', 'updated_at' => '2026-07-02T10:00:00Z', 'labels' => []],
        ]),
    ]);

    // The good repo resolves; the broken one is skipped, not fatal.
    expect(app(ConnectionManager::class)->issueSources())->toHaveCount(1);

    $this->getJson(panelUrl('issues-list'))->assertOk()->assertSee('Timeout on /orders');
});

it('surfaces a single misconfigured tracker as an inline error, not a 500', function (): void {
    config()->set('telemetry-ui.connections.issues', ['driver' => 'github', 'repo' => 'no-slash']);
    registerIssuesPage();

    $this->getJson(panelUrl('issues-list'))
        ->assertOk()
        ->assertSee('owner/name'); // the validation message, returned as the panel's error
});
