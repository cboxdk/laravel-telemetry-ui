<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Queries\Results;

use DateTimeImmutable;

/**
 * A normalized issue from an external tracker (GitHub, Sentry, Linear, …).
 */
final readonly class Issue
{
    /**
     * @param  list<string>  $labels
     */
    public function __construct(
        public string $id,
        public string $title,
        public string $state,
        public string $url,
        public ?string $author,
        public array $labels,
        public ?int $count,
        public ?string $assignee,
        public ?DateTimeImmutable $createdAt,
        public ?DateTimeImmutable $updatedAt,
        public string $kind = 'issue',
    ) {}

    public function isOpen(): bool
    {
        return in_array(strtolower($this->state), ['open', 'unresolved', 'todo', 'in progress', 'started'], true);
    }
}
