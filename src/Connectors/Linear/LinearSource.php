<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Connectors\Linear;

use Cbox\TelemetryUi\Connectors\ApiClient;
use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Contracts\CreatesIssues;
use Cbox\TelemetryUi\Contracts\IssuesSource;
use Cbox\TelemetryUi\Queries\Results\Issue;
use DateTimeImmutable;
use Throwable;

/**
 * Linear issues via its GraphQL API.
 */
final readonly class LinearSource implements CreatesIssues, IssuesSource
{
    private const QUERY = <<<'GRAPHQL'
    query($filter: IssueFilter, $first: Int!) {
      issues(filter: $filter, first: $first, orderBy: updatedAt) {
        nodes {
          identifier title url createdAt updatedAt
          state { name type }
          assignee { name }
          labels { nodes { name } }
        }
      }
    }
    GRAPHQL;

    private const CREATE = <<<'GRAPHQL'
    mutation($input: IssueCreateInput!) {
      issueCreate(input: $input) {
        issue { identifier title url createdAt updatedAt state { name type } }
      }
    }
    GRAPHQL;

    public function __construct(
        private ApiClient $client,
        private ?string $team = null,
        private ?string $teamId = null,
    ) {}

    public function issues(string $state = 'open', ?string $search = null, int $limit = 50): array
    {
        $filter = [];

        // Linear state types: triage, backlog, unstarted, started, completed, canceled.
        if ($state === 'open') {
            $filter['state'] = ['type' => ['nin' => ['completed', 'canceled']]];
        } elseif ($state === 'closed') {
            $filter['state'] = ['type' => ['in' => ['completed', 'canceled']]];
        }

        if ($this->team !== null && $this->team !== '') {
            $filter['team'] = ['key' => ['eq' => $this->team]];
        }

        if ($search !== null && $search !== '') {
            $filter['title'] = ['containsIgnoreCase' => $search];
        }

        $response = $this->client->post('/graphql', [
            'query' => self::QUERY,
            // A string-keyed array serializes to a JSON object; only an empty
            // filter needs the cast so it isn't sent as "[]".
            'variables' => ['filter' => $filter === [] ? (object) [] : $filter, 'first' => min(max($limit, 1), 100)],
        ]);

        $this->assertNoGraphqlErrors($response);

        $nodes = $response['data']['issues']['nodes'] ?? null;
        $nodes = is_array($nodes) ? $nodes : [];

        $issues = [];

        foreach ($nodes as $node) {
            if (! is_array($node) || ! isset($node['identifier'])) {
                continue;
            }

            $labelNodes = is_array($node['labels']['nodes'] ?? null) ? $node['labels']['nodes'] : [];
            $labels = [];
            foreach ($labelNodes as $label) {
                if (is_array($label) && is_string($label['name'] ?? null)) {
                    $labels[] = $label['name'];
                }
            }

            $issues[] = new Issue(
                id: (string) $node['identifier'],
                title: (string) ($node['title'] ?? ''),
                state: is_array($node['state'] ?? null) ? (string) ($node['state']['name'] ?? '') : '',
                url: (string) ($node['url'] ?? ''),
                author: null,
                labels: $labels,
                count: null,
                assignee: is_array($node['assignee'] ?? null) ? ($node['assignee']['name'] ?? null) : null,
                createdAt: $this->date($node['createdAt'] ?? null),
                updatedAt: $this->date($node['updatedAt'] ?? null),
            );
        }

        return $issues;
    }

    public function createIssue(string $title, string $body, array $labels = []): Issue
    {
        if ($this->teamId === null || $this->teamId === '') {
            throw SourceException::because('Linear needs a "team_id" (the team UUID) to create issues.');
        }

        $response = $this->client->post('/graphql', [
            'query' => self::CREATE,
            'variables' => ['input' => ['teamId' => $this->teamId, 'title' => $title, 'description' => $body]],
        ]);

        $this->assertNoGraphqlErrors($response);

        $node = $response['data']['issueCreate']['issue'] ?? null;

        if (! is_array($node)) {
            throw SourceException::because('Linear did not return the created issue.');
        }

        return new Issue(
            id: (string) ($node['identifier'] ?? ''),
            title: (string) ($node['title'] ?? $title),
            state: is_array($node['state'] ?? null) ? (string) ($node['state']['name'] ?? '') : '',
            url: (string) ($node['url'] ?? ''),
            author: null,
            labels: $labels,
            count: null,
            assignee: null,
            createdAt: $this->date($node['createdAt'] ?? null),
            updatedAt: $this->date($node['updatedAt'] ?? null),
            body: $body,
        );
    }

    public function issue(string $id): ?Issue
    {
        foreach ($this->issues('all', null, 100) as $issue) {
            if ($issue->id === $id) {
                return $issue;
            }
        }

        return null;
    }

    public function label(): string
    {
        return $this->team !== null && $this->team !== '' ? 'Linear · '.$this->team : 'Linear';
    }

    public function url(): string
    {
        return 'https://linear.app';
    }

    /**
     * Linear answers HTTP 200 even for auth/permission/query failures, putting
     * the reason in a GraphQL `errors` array with `data: null`. Surface that
     * as a real error instead of silently returning an empty list.
     *
     * @param  array<string, mixed>  $response
     */
    private function assertNoGraphqlErrors(array $response): void
    {
        $errors = $response['errors'] ?? null;

        if (! is_array($errors) || $errors === []) {
            return;
        }

        $messages = [];
        foreach ($errors as $error) {
            if (is_array($error) && is_string($error['message'] ?? null)) {
                $messages[] = $error['message'];
            }
        }

        throw SourceException::because('Linear GraphQL error: '.($messages === [] ? 'unknown error' : implode('; ', $messages)));
    }

    private function date(mixed $value): ?DateTimeImmutable
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (Throwable) {
            return null;
        }
    }
}
