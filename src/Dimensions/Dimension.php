<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Dimensions;

use Closure;

/**
 * A first-class dimension: an attribute the UI filters, groups and drills by.
 *
 * Every attribute is filterable by its raw key; a *declared* dimension is one
 * the host (or the package) promotes with a label, a facet-panel group and an
 * optional link back into the host app:
 *
 *     TelemetryUi::dimension('hubhus.customer_id', label: 'Customer', group: 'Hubhus',
 *         link: fn ($id) => route('customers.show', $id));
 *
 * Declared dimensions appear in the facet sidebar, group-by menus and the
 * filter bar, and as clickable chips on every trace and request — and each of
 * them has an entity page (`/entities/{slug}/{value}`) that tells its story.
 */
final readonly class Dimension
{
    /**
     * @param  string  $scope  where the attribute lives: 'span', 'resource' or 'intrinsic' (TraceQL status/name/duration/kind)
     * @param  (Closure(string): (string|null))|string|null  $link  a URL template with `{value}`, or a closure — a link OUT to the host
     * @param  list<string>  $signals  which Explore signals facet on it by default
     */
    public function __construct(
        public string $key,
        public string $label,
        public ?string $group = null,
        public Closure|string|null $link = null,
        public ?string $entity = null,
        public string $scope = 'span',
        public bool $builtin = false,
        public array $signals = ['requests', 'traces'],
        public ?string $format = null,
        public ?string $plural = null,
    ) {}

    /**
     * The entity slug this dimension's values open under: its declared alias
     * (`route`) or the raw key (`hubhus.customer_id`).
     */
    public function entitySlug(): string
    {
        return $this->entity ?? $this->key;
    }

    /**
     * The link out to the host app for one value, or null. A throwing closure
     * (a route that no longer exists) yields no link rather than a 500.
     */
    public function linkFor(string $value): ?string
    {
        if ($this->link === null || $value === '') {
            return null;
        }

        if (is_string($this->link)) {
            return str_replace('{value}', rawurlencode($value), $this->link);
        }

        try {
            $url = ($this->link)($value);
        } catch (\Throwable) {
            return null;
        }

        return is_string($url) && $url !== '' ? $url : null;
    }

    /**
     * The TraceQL field for this dimension (`span.x`, `resource.x`, or the
     * intrinsic itself).
     */
    public function traceField(): string
    {
        return match ($this->scope) {
            'intrinsic' => $this->key,
            'resource' => 'resource.'.$this->key,
            default => 'span.'.$this->key,
        };
    }

    /**
     * @return array{key: string, label: string, group: string|null, entity: string, scope: string, builtin: bool, signals: list<string>, format: string|null, plural: string, linksOut: bool}
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'group' => $this->group,
            'entity' => $this->entitySlug(),
            'scope' => $this->scope,
            'builtin' => $this->builtin,
            'signals' => $this->signals,
            'format' => $this->format,
            'plural' => $this->plural ?? $this->label.'s',
            'linksOut' => $this->link !== null,
        ];
    }
}
