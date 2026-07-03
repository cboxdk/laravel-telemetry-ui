<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Connectors\GitHub;

use Cbox\TelemetryUi\Connectors\ApiClient;
use Cbox\TelemetryUi\Contracts\IssuesSource;
use Cbox\TelemetryUi\Queries\Results\Issue;
use DateTimeImmutable;
use Throwable;

/**
 * GitHub issues + pull requests for a repo (the REST issues endpoint returns
 * both; PRs carry a `pull_request` key).
 */
final readonly class GitHubSource implements IssuesSource
{
    public function __construct(
        private ApiClient $client,
        private string $repo,
    ) {}

    public function issues(string $state = 'open', ?string $search = null, int $limit = 50): array
    {
        /** @var array<int, mixed> $raw */
        $raw = $this->client->get('/repos/'.$this->repo.'/issues', [
            'state' => $state,
            'per_page' => min(max($limit, 1), 100),
            'sort' => 'updated',
            'direction' => 'desc',
        ]);

        $issues = [];

        foreach ($raw as $item) {
            if (! is_array($item) || ! isset($item['number'])) {
                continue;
            }

            $title = (string) ($item['title'] ?? '');

            if ($search !== null && $search !== '' && stripos($title, $search) === false) {
                continue;
            }

            $issues[] = new Issue(
                id: '#'.$item['number'],
                title: $title,
                state: (string) ($item['state'] ?? 'open'),
                url: (string) ($item['html_url'] ?? ''),
                author: $this->nested($item, 'user', 'login'),
                labels: $this->labels($item),
                count: isset($item['comments']) ? (int) $item['comments'] : null,
                assignee: $this->nested($item, 'assignee', 'login'),
                createdAt: $this->date($item['created_at'] ?? null),
                updatedAt: $this->date($item['updated_at'] ?? null),
                kind: isset($item['pull_request']) ? 'pr' : 'issue',
            );
        }

        return $issues;
    }

    public function label(): string
    {
        return $this->repo;
    }

    public function url(): string
    {
        return 'https://github.com/'.$this->repo;
    }

    /**
     * @param  array<array-key, mixed>  $item
     */
    private function nested(array $item, string $key, string $child): ?string
    {
        $value = is_array($item[$key] ?? null) ? ($item[$key][$child] ?? null) : null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @param  array<array-key, mixed>  $item
     * @return list<string>
     */
    private function labels(array $item): array
    {
        $labels = is_array($item['labels'] ?? null) ? $item['labels'] : [];

        $names = [];

        foreach ($labels as $label) {
            $name = is_array($label) ? ($label['name'] ?? null) : (is_string($label) ? $label : null);

            if (is_string($name) && $name !== '') {
                $names[] = $name;
            }
        }

        return $names;
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
