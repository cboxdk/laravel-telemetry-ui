<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Analysis;

/**
 * Two diagnosed traces side by side: did the fix land? Duration, totals and
 * time per category as before/after/delta; query shapes that appeared,
 * disappeared or changed how often they ran; flags present on one side only
 * (matched by their stable key, so "N+1: 40× …" and "N+1: 12× …" on the same
 * shape are the same flag, still there).
 *
 * @phpstan-import-type Shape from TraceDiagnosis
 */
final class TraceComparison
{
    private const LIST_SHORT = 5;

    private const LIST_VERBOSE = 25;

    /**
     * @return array<string, mixed>
     */
    public static function between(TraceDiagnosis $a, TraceDiagnosis $b, bool $verbose = false): array
    {
        $durationA = (float) $a->identity['duration_ms'];
        $durationB = (float) $b->identity['duration_ms'];

        return [
            'a' => self::side($a),
            'b' => self::side($b),
            'duration_ms' => self::delta($durationA, $durationB) + [
                'change_pct' => $durationA > 0.0 ? round(($durationB - $durationA) / $durationA * 100, 1) : null,
            ],
            'totals' => self::deltas($a->totals, $b->totals),
            'timing_ms' => self::deltas(
                array_filter($a->timing, static fn (float $ms): bool => $ms > 0.0),
                array_filter($b->timing, static fn (float $ms): bool => $ms > 0.0),
            ),
            'queries' => self::shapes($a->shapes, $b->shapes, $verbose),
            'flags' => self::flags($a->flags, $b->flags, $verbose),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function side(TraceDiagnosis $d): array
    {
        return array_intersect_key($d->identity, array_flip(['trace_id', 'name', 'service', 'label', 'status', 'started_at']));
    }

    /**
     * @return array{a: float|int, b: float|int, delta: float|int}
     */
    private static function delta(float|int $a, float|int $b): array
    {
        $delta = $b - $a;

        return ['a' => $a, 'b' => $b, 'delta' => is_float($delta) ? round($delta, 2) : $delta];
    }

    /**
     * @param  array<string, float|int>  $a
     * @param  array<string, float|int>  $b
     * @return array<string, array{a: float|int, b: float|int, delta: float|int}>
     */
    private static function deltas(array $a, array $b): array
    {
        $out = [];

        foreach (array_keys($a + $b) as $key) {
            $out[$key] = self::delta($a[$key] ?? 0, $b[$key] ?? 0);
        }

        return $out;
    }

    /**
     * @param  array<string, Shape>  $a
     * @param  array<string, Shape>  $b
     * @return array<string, mixed>
     */
    private static function shapes(array $a, array $b, bool $verbose): array
    {
        $max = $verbose ? self::LIST_VERBOSE : self::LIST_SHORT;
        $text = static fn (array $s): string => $verbose ? $s['text'] : TraceDiagnosis::shortText($s['text']);

        $appeared = array_diff_key($b, $a);
        $disappeared = array_diff_key($a, $b);
        $changed = [];
        $unchanged = 0;

        foreach (array_intersect_key($a, $b) as $key => $before) {
            $after = $b[$key];

            if ($before['count'] === $after['count']) {
                $unchanged++;

                continue;
            }

            $changed[] = [
                'query' => $text($after),
                'origin' => $after['origin'] !== '' ? $after['origin'] : $before['origin'],
                'a' => $before['count'],
                'b' => $after['count'],
                'delta' => $after['count'] - $before['count'],
            ];
        }

        usort($changed, static fn (array $x, array $y): int => abs($y['delta']) <=> abs($x['delta']));

        $list = static fn (array $shapes): array => array_map(static fn (array $s): array => [
            'query' => $text($s),
            'count' => $s['count'],
            'origin' => $s['origin'],
        ], array_slice(array_values($shapes), 0, $max));

        return [
            'shapes' => ['a' => count($a), 'b' => count($b)],
            'appeared' => count($appeared),
            'disappeared' => count($disappeared),
            'changed' => count($changed),
            'unchanged' => $unchanged,
            'appeared_top' => $list($appeared),
            'disappeared_top' => $list($disappeared),
            'changed_top' => array_slice($changed, 0, $max),
        ];
    }

    /**
     * @param  list<array{level: string, section: string, message: string, origin?: string, key: string, detail?: array<string, mixed>}>  $a
     * @param  list<array{level: string, section: string, message: string, origin?: string, key: string, detail?: array<string, mixed>}>  $b
     * @return array<string, mixed>
     */
    private static function flags(array $a, array $b, bool $verbose): array
    {
        $keysA = array_column($a, 'key');
        $keysB = array_column($b, 'key');

        $strip = static function (array $flag) use ($verbose): array {
            unset($flag['key']);

            if (! $verbose) {
                unset($flag['detail']);
            }

            return $flag;
        };

        return [
            'only_a' => array_values(array_map($strip, array_filter($a, static fn (array $f): bool => ! in_array($f['key'], $keysB, true)))),
            'only_b' => array_values(array_map($strip, array_filter($b, static fn (array $f): bool => ! in_array($f['key'], $keysA, true)))),
            'both' => count(array_intersect($keysA, $keysB)),
        ];
    }
}
