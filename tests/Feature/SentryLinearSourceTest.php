<?php

declare(strict_types=1);

use Cbox\TelemetryUi\Connectors\ConnectionManager;
use Cbox\TelemetryUi\Queries\Results\Issue;
use Illuminate\Support\Facades\Http;

it('lists sentry issues for a project', function (): void {
    Http::fake([
        'sentry.io/api/0/projects/cbox/web/issues/*' => Http::response([
            [
                'id' => '123',
                'shortId' => 'WEB-4F',
                'title' => 'Predis\\TimeoutException: Operation timed out',
                'culprit' => 'App\\Jobs\\Ship',
                'level' => 'error',
                'status' => 'unresolved',
                'permalink' => 'https://sentry.io/organizations/cbox/issues/123/',
                'count' => '540',
                'metadata' => ['type' => 'Predis\\TimeoutException'],
                'firstSeen' => '2026-06-01T10:00:00Z',
                'lastSeen' => '2026-07-03T12:00:00Z',
                'assignedTo' => ['name' => 'Sylvester'],
            ],
        ]),
    ]);

    config()->set('telemetry-ui.connections.issues', [
        'driver' => 'sentry', 'org' => 'cbox', 'project' => 'web', 'token' => 'sntrys_x',
    ]);

    $issues = app(ConnectionManager::class)->issues()->issues('open');

    expect($issues)->toHaveCount(1)
        ->and($issues[0])->toBeInstanceOf(Issue::class)
        ->and($issues[0]->id)->toBe('WEB-4F')
        ->and($issues[0]->title)->toContain('TimeoutException')
        ->and($issues[0]->labels)->toBe(['error', 'Predis\\TimeoutException'])
        ->and($issues[0]->count)->toBe(540)
        ->and($issues[0]->assignee)->toBe('Sylvester');

    Http::assertSent(fn ($request): bool => $request->hasHeader('Authorization', 'Bearer sntrys_x')
        && str_contains(rawurldecode($request->url()), 'query=is:unresolved'));
});

it('lists linear issues via graphql with the raw api key', function (): void {
    Http::fake([
        'api.linear.app/graphql' => Http::response([
            'data' => ['issues' => ['nodes' => [
                [
                    'identifier' => 'CBOX-42',
                    'title' => 'Autoscaler flaps under burst load',
                    'url' => 'https://linear.app/cbox/issue/CBOX-42',
                    'createdAt' => '2026-07-01T10:00:00Z',
                    'updatedAt' => '2026-07-03T09:00:00Z',
                    'state' => ['name' => 'In Progress', 'type' => 'started'],
                    'assignee' => ['name' => 'Sylvester'],
                    'labels' => ['nodes' => [['name' => 'bug'], ['name' => 'infra']]],
                ],
            ]]],
        ]),
    ]);

    config()->set('telemetry-ui.connections.issues', [
        'driver' => 'linear', 'token' => 'lin_api_key', 'team' => 'CBOX',
    ]);

    $issues = app(ConnectionManager::class)->issues()->issues('open', 'autoscaler');

    expect($issues)->toHaveCount(1)
        ->and($issues[0]->id)->toBe('CBOX-42')
        ->and($issues[0]->state)->toBe('In Progress')
        ->and($issues[0]->labels)->toBe(['bug', 'infra'])
        ->and($issues[0]->assignee)->toBe('Sylvester');

    Http::assertSent(function ($request): bool {
        $body = $request->data();

        return $request->hasHeader('Authorization', 'lin_api_key')
            && str_contains((string) ($body['query'] ?? ''), 'issues(filter:')
            && ($body['variables']['filter']['title']['containsIgnoreCase'] ?? null) === 'autoscaler';
    });
});

it('rejects a sentry connection without org/project', function (): void {
    config()->set('telemetry-ui.connections.issues', ['driver' => 'sentry', 'org' => 'cbox']);

    app(ConnectionManager::class)->issues();
})->throws(InvalidArgumentException::class, 'org');
