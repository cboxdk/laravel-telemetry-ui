<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Support;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Http\Request;

/**
 * The timezone a viewer reads times in. The SPA sends its own as `X-Timezone`:
 * the browser's, or the one the viewer picked. Without it (an API call by
 * hand, an MCP tool) the host's configured default applies, then the app's.
 *
 * Every clock the server formats for display goes through here, so a table
 * row and the chart above it show the same hour. Bound request-scoped: one
 * viewer's zone must never format the next request's times.
 */
final class DisplayTime
{
    public const HEADER = 'X-Timezone';

    /** @var array<string, true>|null */
    private static ?array $known = null;

    public function __construct(public readonly DateTimeZone $zone) {}

    public static function fromRequest(?Request $request, mixed $default, mixed $app): self
    {
        foreach ([$request?->header(self::HEADER), $default, $app] as $name) {
            if (is_string($name) && ($zone = self::zone($name)) !== null) {
                return new self($zone);
            }
        }

        return new self(new DateTimeZone('UTC'));
    }

    /**
     * A named IANA zone, or null. Offsets and abbreviations are refused: only
     * names keep daylight saving right. Backward-compatible names count, since
     * browsers still report some of them (Asia/Calcutta).
     */
    public static function zone(string $name): ?DateTimeZone
    {
        self::$known ??= array_fill_keys(DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC), true);

        return isset(self::$known[$name = trim($name)]) ? new DateTimeZone($name) : null;
    }

    /** {@see format()} in the current request's zone. */
    public static function at(DateTimeInterface|int $at, string $pattern): string
    {
        return app(self::class)->format($at, $pattern);
    }

    /** {@see stamp()} in the current request's zone. */
    public static function short(DateTimeInterface|int $at): string
    {
        return app(self::class)->stamp($at);
    }

    /** `$at` (an int is Unix seconds) as wall-clock text in this zone. */
    public function format(DateTimeInterface|int $at, string $pattern): string
    {
        $time = $at instanceof DateTimeInterface ? DateTimeImmutable::createFromInterface($at) : new DateTimeImmutable('@'.$at);

        return $time->setTimezone($this->zone)->format($pattern);
    }

    /** A time alone when it fell today here, else the date too: a time alone is ambiguous across days. */
    public function stamp(DateTimeInterface|int $at): string
    {
        $today = (new DateTimeImmutable('now', $this->zone))->format('Y-m-d');

        return $this->format($at, $this->format($at, 'Y-m-d') === $today ? 'H:i:s' : 'M j H:i');
    }
}
