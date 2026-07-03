<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Queries\Results;

final readonly class Span
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function __construct(
        public string $spanId,
        public ?string $parentSpanId,
        public string $name,
        public string $serviceName,
        public SpanKind $kind,
        public int $startNano,
        public int $endNano,
        public array $attributes,
        public bool $hasError,
    ) {}

    public function durationMs(): float
    {
        return ($this->endNano - $this->startNano) / 1_000_000;
    }

    public function isRoot(): bool
    {
        return $this->parentSpanId === null;
    }

    /**
     * A one-line, human summary of what this span actually did — the most
     * telling attribute for its kind. Shown inline in the waterfall so the
     * span name ("db.query", "cache") isn't the only context.
     */
    public function summary(): ?string
    {
        $a = $this->attributes;

        // Database query — the statement itself.
        if (isset($a['db.query.text']) && is_scalar($a['db.query.text'])) {
            return $this->truncate((string) $a['db.query.text'], 90);
        }

        // HTTP server/client — method, target and status.
        $method = $this->str($a['http.request.method'] ?? null);
        $target = $this->str($a['http.route'] ?? $a['url.path'] ?? $a['url.full'] ?? $a['server.address'] ?? null);
        $status = $this->str($a['http.response.status_code'] ?? null);

        if ($method !== null || $target !== null) {
            return trim(($method ?? '').' '.($target ?? '').($status !== null ? ' → '.$status : ''));
        }

        // Messaging (queue) — destination.
        if (($dest = $this->str($a['messaging.destination.name'] ?? null)) !== null) {
            return 'queue: '.$dest;
        }

        // Cache — the key or its classified group.
        if (($key = $this->str($a['cache.key'] ?? $a['cache.key.group'] ?? null)) !== null) {
            return 'key: '.$key;
        }

        // View render.
        if (($view = $this->str($a['view.name'] ?? null)) !== null) {
            return 'view: '.$view;
        }

        // Exception on the span.
        if (($ex = $this->str($a['exception.type'] ?? null)) !== null) {
            return $ex;
        }

        return null;
    }

    private function str(mixed $value): ?string
    {
        return is_scalar($value) && (string) $value !== '' ? (string) $value : null;
    }

    private function truncate(string $value, int $max): string
    {
        $value = trim((string) preg_replace('/\s+/', ' ', $value));

        return mb_strlen($value) > $max ? mb_substr($value, 0, $max).'…' : $value;
    }
}
