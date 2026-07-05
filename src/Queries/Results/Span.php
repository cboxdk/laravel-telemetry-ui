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
     * Whether this span ran in the browser (RUM). The frontend proxy in
     * cboxdk/laravel-telemetry stamps a server-side `browser=true` attribute
     * the browser can't forge; browser spans otherwise share the backend's
     * service.name, so this per-span flag — not the service — is the frontend
     * marker in a unified trace.
     */
    public function isBrowser(): bool
    {
        return self::attributesAreBrowser($this->attributes);
    }

    /**
     * The `browser=true` test on a raw attribute bag — for callers that hold
     * span attributes but not a Span (e.g. grouping matched spans by origin).
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function attributesAreBrowser(array $attributes): bool
    {
        $value = $attributes['browser'] ?? null;

        return $value === true || $value === 1 || $value === '1'
            || (is_string($value) && strtolower($value) === 'true');
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

        // Browser page load (RUM) — the key navigation timings.
        $ttfb = $this->str($a['browser.ttfb_ms'] ?? null);
        $domInteractive = $this->str($a['browser.dom_interactive_ms'] ?? null);
        if ($ttfb !== null || $domInteractive !== null) {
            $timings = array_filter([
                $ttfb !== null ? 'TTFB '.$ttfb.'ms' : null,
                $domInteractive !== null ? 'DOM '.$domInteractive.'ms' : null,
            ]);

            return implode(' · ', $timings);
        }

        // HTTP server/client — method, target and status. `http.url` covers the
        // browser fetch spans, which carry the full URL but no method/route.
        $method = $this->str($a['http.request.method'] ?? null);
        $target = $this->str($a['http.route'] ?? $a['url.path'] ?? $a['url.full'] ?? $a['http.url'] ?? $a['server.address'] ?? null);
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
