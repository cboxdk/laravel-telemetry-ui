<?php

declare(strict_types=1);

use Cbox\TelemetryUi\Cards\Builtin\IssuesList;
use Cbox\TelemetryUi\Connectors\ConnectionManager;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

function fakeTwoRepos(): void
{
    config()->set('telemetry-ui.connections.issues', [
        ['driver' => 'github', 'repo' => 'cboxdk/frontend', 'token' => 'ghp_x', 'label' => 'frontend'],
        ['driver' => 'github', 'repo' => 'cboxdk/api', 'token' => 'ghp_y', 'label' => 'api'],
    ]);

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

    Livewire::test(IssuesList::class)
        ->assertSee('Button misaligned')
        ->assertSee('Timeout on /orders')
        ->assertSee('frontend')            // source badges
        ->assertSee('api')
        ->assertSee('All repos')           // the source filter appears
        // Filtering to one repo drops the other.
        ->set('sourceFilter', 'api')
        ->assertSee('Timeout on /orders')
        ->assertDontSee('Button misaligned');
});
