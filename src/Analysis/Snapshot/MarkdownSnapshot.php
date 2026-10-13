<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Analysis\Snapshot;

/**
 * A bounded Markdown snapshot — of a trace ({@see TraceSnapshot}) or an error
 * group ({@see ExceptionSnapshot}) — built to paste into an AI agent, a pull
 * request or a support ticket.
 *
 * Sections are assembled in priority order under a hard byte cap. The title
 * is always kept; the first section that does not fit is cut at a line (with
 * any open code fence closed) and the rest are left out — and the snapshot
 * says so in its last line, naming what was cut, so a reader never mistakes a
 * truncated snapshot for the whole story.
 */
final readonly class MarkdownSnapshot
{
    /** The hard cap on a snapshot, in bytes: ~12 KB fits any chat box or ticket. */
    public const MAX_BYTES = 12_288;

    /** Kept free for the truncation note. */
    private const NOTE_RESERVE = 320;

    /** A section is cut rather than dropped only when this much of it would fit. */
    private const MIN_CUT = 400;

    /**
     * @param  list<string>  $omitted  sections left out (or cut) to stay under the cap
     */
    public function __construct(
        public string $markdown,
        public bool $truncated = false,
        public array $omitted = [],
    ) {}

    public function bytes(): int
    {
        return strlen($this->markdown);
    }

    /**
     * @return array{markdown: string, bytes: int, truncated: bool, omitted: list<string>}
     */
    public function toArray(): array
    {
        return [
            'markdown' => $this->markdown,
            'bytes' => $this->bytes(),
            'truncated' => $this->truncated,
            'omitted' => $this->omitted,
        ];
    }

    /**
     * @param  list<array{0: string, 1: string}>  $sections  [name, markdown], in priority order; the first is the title
     * @param  string  $footer  appended when it fits (never at the cost of a section)
     */
    public static function assemble(array $sections, string $footer = '', int $maxBytes = self::MAX_BYTES): self
    {
        $sections = array_values(array_filter($sections, static fn (array $s): bool => trim($s[1]) !== ''));
        $budget = max(self::MIN_CUT, $maxBytes - self::NOTE_RESERVE);
        $out = '';
        $omitted = [];
        $cut = null;

        foreach ($sections as $i => [$name, $markdown]) {
            $markdown = rtrim($markdown);
            $piece = ($out === '' ? '' : "\n\n").$markdown;

            if ($omitted === [] && $cut === null && strlen($out) + strlen($piece) <= $budget) {
                $out .= $piece;

                continue;
            }

            $room = $budget - strlen($out) - 2;

            if ($cut === null && $omitted === [] && ($i === 0 || $room >= self::MIN_CUT)) {
                $out .= ($out === '' ? '' : "\n\n").self::cut($markdown, $room);
                $cut = $name;

                continue;
            }

            $omitted[] = $name;
        }

        if ($cut === null && $omitted === []) {
            if ($footer !== '' && strlen($out) + strlen($footer) + 2 <= $maxBytes) {
                $out .= "\n\n".$footer;
            }

            return new self($out."\n");
        }

        $note = '> **Truncated** to stay under '.self::kb($maxBytes).' KB:'
            .($cut !== null ? ' "'.$cut.'" was cut short' : '')
            .($cut !== null && $omitted !== [] ? ';' : '')
            .($omitted !== [] ? ' left out: '.implode(', ', array_map(static fn (string $n): string => '"'.$n.'"', $omitted)) : '')
            .'.';

        // A long list of omitted names must not break the cap it reports.
        if (strlen($out) + strlen($note) + 3 > $maxBytes) {
            $note = '> **Truncated** to stay under '.self::kb($maxBytes).' KB; some sections were left out.';
        }

        return new self(
            $out."\n\n".$note."\n",
            true,
            array_values(array_filter([$cut, ...$omitted], static fn (?string $n): bool => $n !== null)),
        );
    }

    /**
     * A fenced code block whose fence is longer than any backtick run in the
     * text, so SQL quoted with backticks (MySQL) can never close it early.
     */
    public static function fence(string $text, string $language = ''): string
    {
        $fence = str_repeat('`', max(3, self::longestRun($text, '`') + 1));

        return $fence.$language."\n".rtrim($text, "\n")."\n".$fence;
    }

    /** Inline code, safe for text that itself contains backticks. */
    public static function code(string $text): string
    {
        $text = self::oneLine($text);
        $ticks = str_repeat('`', self::longestRun($text, '`') + 1);
        $pad = str_starts_with($text, '`') || str_ends_with($text, '`') || strlen($ticks) > 1 ? ' ' : '';

        return $ticks.$pad.$text.$pad.$ticks;
    }

    /**
     * Free text on one Markdown line: whitespace collapsed, backticks escaped
     * (a message quoting SQL would otherwise open code spans), capped.
     */
    public static function text(string $text, int $max = 300): string
    {
        return str_replace('`', '\\`', self::oneLine($text, $max));
    }

    /** Free text in a table cell: {@see text()} plus escaped pipes. */
    public static function cell(string $text, int $max = 120): string
    {
        return str_replace('|', '\\|', self::text($text, $max));
    }

    public static function oneLine(string $text, int $max = 0): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));

        return $max > 0 && mb_strlen($text) > $max ? mb_substr($text, 0, $max).'…' : $text;
    }

    /** At most $max bytes of $text, cut at a character boundary, marked when cut. */
    public static function limit(string $text, int $max): string
    {
        if (strlen($text) <= $max) {
            return $text;
        }

        return mb_strcut($text, 0, max(0, $max - 4)).' […]';
    }

    /** Milliseconds for a reader: 0.4 ms, 12.3 ms, 1,204 ms. */
    public static function ms(float $ms): string
    {
        return ($ms >= 1000 ? number_format($ms) : (string) round($ms, 1)).' ms';
    }

    /**
     * The first $room bytes of a section, cut at a line, with a code fence it
     * leaves open closed again and the cut marked.
     */
    private static function cut(string $markdown, int $room): string
    {
        $room = max(0, $room - 16);
        $kept = [];
        $used = 0;
        $fence = null;

        foreach (explode("\n", $markdown) as $line) {
            $cost = strlen($line) + 1 + ($fence !== null ? strlen($fence) + 1 : 0);

            if ($used + $cost > $room) {
                break;
            }

            $kept[] = $line;
            $used += strlen($line) + 1;

            if (preg_match('/^(`{3,})/', $line, $m) === 1) {
                $fence = $fence === null ? $m[1] : ($m[1] === $fence && trim($line) === $fence ? null : $fence);
            }
        }

        if ($fence !== null) {
            $kept[] = $fence;
        }

        $kept[] = '[…]';

        return implode("\n", $kept);
    }

    private static function longestRun(string $text, string $char): int
    {
        $longest = 0;

        if (preg_match_all('/'.preg_quote($char, '/').'+/', $text, $matches) > 0) {
            foreach ($matches[0] as $run) {
                $longest = max($longest, strlen($run));
            }
        }

        return $longest;
    }

    private static function kb(int $bytes): string
    {
        return (string) round($bytes / 1024);
    }
}
