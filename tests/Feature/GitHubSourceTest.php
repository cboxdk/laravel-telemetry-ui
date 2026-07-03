<?php

declare(strict_types=1);

use Cbox\TelemetryUi\Connectors\ApiClient;
use Cbox\TelemetryUi\Connectors\ConnectionManager;
use Cbox\TelemetryUi\Connectors\GitHub\GitHubSource;
use Cbox\TelemetryUi\Queries\Results\Issue;
use Illuminate\Support\Facades\Http;

function fakeGitHub(): void
{
    Http::fake([
        'api.github.com/repos/cboxdk/laravel-telemetry-ui/issues*' => Http::response([
            [
                'number' => 42,
                'title' => 'Charts render blank on Safari',
                'state' => 'open',
                'html_url' => 'https://github.com/cboxdk/laravel-telemetry-ui/issues/42',
                'user' => ['login' => 'sylvesterdamgaard'],
                'labels' => [['name' => 'bug'], ['name' => 'ui']],
                'comments' => 3,
                'assignee' => ['login' => 'octocat'],
                'created_at' => '2026-07-01T10:00:00Z',
                'updated_at' => '2026-07-03T12:00:00Z',
            ],
            [
                'number' => 41,
                'title' => 'Add Linear driver',
                'state' => 'open',
                'html_url' => 'https://github.com/cboxdk/laravel-telemetry-ui/pull/41',
                'user' => ['login' => 'contributor'],
                'labels' => [],
                'comments' => 0,
                'assignee' => null,
                'created_at' => '2026-07-02T10:00:00Z',
                'updated_at' => '2026-07-02T11:00:00Z',
                'pull_request' => ['url' => 'https://api.github.com/…/pulls/41'],
            ],
        ]),
    ]);
}

it('lists github issues and distinguishes pull requests', function (): void {
    fakeGitHub();

    config()->set('telemetry-ui.connections.issues', [
        'driver' => 'github',
        'repo' => 'cboxdk/laravel-telemetry-ui',
        'token' => 'ghp_test',
    ]);

    $issues = app(ConnectionManager::class)->issues()->issues('open');

    expect($issues)->toHaveCount(2)
        ->and($issues[0])->toBeInstanceOf(Issue::class)
        ->and($issues[0]->id)->toBe('#42')
        ->and($issues[0]->title)->toBe('Charts render blank on Safari')
        ->and($issues[0]->kind)->toBe('issue')
        ->and($issues[0]->labels)->toBe(['bug', 'ui'])
        ->and($issues[0]->author)->toBe('sylvesterdamgaard')
        ->and($issues[0]->count)->toBe(3)
        ->and($issues[0]->isOpen())->toBeTrue()
        ->and($issues[1]->kind)->toBe('pr');

    Http::assertSent(fn ($request): bool => $request->hasHeader('Authorization', 'Bearer ghp_test')
        && $request->hasHeader('Accept', 'application/vnd.github+json')
        && str_contains($request->url(), '/repos/cboxdk/laravel-telemetry-ui/issues')
        && str_contains($request->url(), 'state=open'));
});

it('filters by a title search client-side', function (): void {
    fakeGitHub();

    $source = new GitHubSource(
        new ApiClient('https://api.github.com'),
        'cboxdk/laravel-telemetry-ui',
    );

    $issues = $source->issues('open', 'linear');

    expect($issues)->toHaveCount(1)
        ->and($issues[0]->title)->toBe('Add Linear driver');
});

it('reports whether an issues connection is configured', function (): void {
    $manager = app(ConnectionManager::class);

    expect($manager->hasIssues())->toBeFalse();

    config()->set('telemetry-ui.connections.issues.driver', 'github');

    expect($manager->hasIssues())->toBeTrue();
});

it('requires an owner/name repo', function (): void {
    config()->set('telemetry-ui.connections.issues', ['driver' => 'github', 'repo' => 'nope']);

    app(ConnectionManager::class)->issues();
})->throws(InvalidArgumentException::class, 'owner/name');
