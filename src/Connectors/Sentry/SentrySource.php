<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Connectors\Sentry;

use Cbox\TelemetryUi\Connectors\ApiClient;
use Cbox\TelemetryUi\Contracts\IssuesSource;
use Cbox\TelemetryUi\Queries\Results\Issue;
use DateTimeImmutable;
use Throwable;

/**
 * Sentry issues for a project — the closest external fit to our own
 * exceptions, with grouping, event counts and first/last seen.
 */
final readonly class SentrySource implements IssuesSource
{
    public function __construct(
        private ApiClient $client,
        private string $organization,
        private string $project,
        private string $baseUrl = 'https://sentry.io',
    ) {}

    public function issues(string $state = 'open', ?string $search = null, int $limit = 50): array
    {
        $query = match ($state) {
            'closed' => 'is:resolved',
            'all' => '',
            default => 'is:unresolved',
        };

        if ($search !== null && $search !== '') {
            $query = trim($query.' '.$search);
        }

        /** @var array<int, mixed> $raw */
        $raw = $this->client->get(
            '/api/0/projects/'.rawurlencode($this->organization).'/'.rawurlencode($this->project).'/issues/',
            array_filter(['query' => $query, 'limit' => min(max($limit, 1), 100)], static fn ($v): bool => $v !== ''),
        );

        $issues = [];

        foreach ($raw as $item) {
            if (! is_array($item) || ! isset($item['id'])) {
                continue;
            }

            $labels = [];
            if (is_string($item['level'] ?? null)) {
                $labels[] = $item['level'];
            }
            if (is_array($item['metadata'] ?? null) && is_string($item['metadata']['type'] ?? null)) {
                $labels[] = $item['metadata']['type'];
            }

            $issues[] = new Issue(
                id: (string) ($item['shortId'] ?? $item['id']),
                title: (string) ($item['title'] ?? $item['culprit'] ?? '(untitled)'),
                state: (string) ($item['status'] ?? 'unresolved'),
                url: (string) ($item['permalink'] ?? ''),
                author: is_string($item['culprit'] ?? null) && $item['culprit'] !== '' ? $item['culprit'] : null,
                labels: $labels,
                count: isset($item['count']) ? (int) $item['count'] : null,
                assignee: is_array($item['assignedTo'] ?? null) ? ($item['assignedTo']['name'] ?? null) : null,
                createdAt: $this->date($item['firstSeen'] ?? null),
                updatedAt: $this->date($item['lastSeen'] ?? null),
            );
        }

        return $issues;
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
        return $this->organization.'/'.$this->project;
    }

    public function url(): string
    {
        return rtrim($this->baseUrl, '/').'/organizations/'.$this->organization.'/issues/?project='.$this->project;
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
