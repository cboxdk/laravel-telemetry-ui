<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Dimensions;

/**
 * A dimension whose values live inside another attribute.
 *
 * Some routing layers encode their own concept in a standard attribute rather
 * than emitting a second one: laravel-telemetry names Livewire updates
 * `livewire:{component}`, and a host's own layer might use `portal:{screen}`.
 * A derivation makes that a first-class dimension — facet, chip, group-by,
 * entity page — without touching the emitter:
 *
 *     TelemetryUi::dimension('portal.screen', label: 'Screen',
 *         from: 'http.route', pattern: 'portal:{value}');
 *
 * The pattern is a template, not a regex: the literal parts around `{value}`
 * are what the emitter writes. That keeps both directions exact — reading a
 * value out of a span, and turning a filter back into a query the backend can
 * answer (`portal.screen = "checkout"` → `http.route = "portal:checkout"`),
 * with no regex over the whole dataset.
 */
final readonly class Derivation
{
    public const PLACEHOLDER = '{value}';

    public string $prefix;

    public string $suffix;

    public function __construct(public string $from, public string $pattern)
    {
        $at = strpos($pattern, self::PLACEHOLDER);

        if ($at === false) {
            throw new \InvalidArgumentException('A dimension pattern must contain '.self::PLACEHOLDER.", got [{$pattern}].");
        }

        $this->prefix = substr($pattern, 0, $at);
        $this->suffix = substr($pattern, $at + strlen(self::PLACEHOLDER));
    }

    /** `checkout` → `portal:checkout`, as the emitter writes it. */
    public function encode(string $value): string
    {
        return $this->prefix.$value.$this->suffix;
    }

    /** `portal:checkout` → `checkout`, or null when the source doesn't match. */
    public function extract(string $source): ?string
    {
        if ($this->prefix !== '' && ! str_starts_with($source, $this->prefix)) {
            return null;
        }

        if ($this->suffix !== '' && ! str_ends_with($source, $this->suffix)) {
            return null;
        }

        $value = substr($source, strlen($this->prefix), strlen($source) - strlen($this->prefix) - strlen($this->suffix));

        return $value === '' ? null : $value;
    }

    /**
     * An anchored regex over the source attribute for one value expression —
     * `.+` for "any value of this dimension", or the reader's own pattern when
     * they filtered with `=~`.
     */
    public function regex(string $valueExpression = '.+'): string
    {
        return '^'.self::quote($this->prefix).$valueExpression.self::quote($this->suffix).'$';
    }

    /**
     * Escape what a regex engine would otherwise read as syntax — and only
     * that. preg_quote() also escapes harmless characters like `:`, and the
     * backslash it adds survives into the backend's string literal, where it
     * would then match a literal backslash.
     */
    private static function quote(string $literal): string
    {
        return (string) preg_replace('/[\\\\.+*?()|\[\]{}^$]/', '\\\\$0', $literal);
    }
}
