<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Analysis\Snapshot;

/**
 * A PHP stacktrace ("#0 /path/File.php(46): Class->method()") read for the
 * app's own frames — the ones a fix lands in. Mirrors the SPA's StackTrace
 * component: a frame under `/vendor/` or `[internal …]` is the framework's,
 * and paths lose the project root every frame shares.
 */
final class StackFrames
{
    private const FRAME = '/^#(\d+)\s+(.+?)\((\d+)\):\s*(.*)$/';

    /**
     * The app frames, as "#n path(line): call" lines, at most $max of them.
     *
     * @return array{frames: list<string>, total: int, app: int}
     */
    public static function app(string $stacktrace, int $max = 8): array
    {
        $parsed = [];

        foreach (explode("\n", $stacktrace) as $line) {
            if (preg_match(self::FRAME, trim($line), $m) === 1) {
                $parsed[] = ['n' => $m[1], 'file' => $m[2], 'line' => $m[3], 'call' => $m[4]];
            }
        }

        $root = self::root(array_column($parsed, 'file'));
        $app = array_values(array_filter(
            $parsed,
            static fn (array $f): bool => ! str_contains($f['file'], '/vendor/') && ! str_starts_with($f['file'], '[internal'),
        ));

        return [
            'frames' => array_map(
                static fn (array $f): string => '#'.$f['n'].' '.($root !== '' && str_starts_with($f['file'], $root) ? substr($f['file'], strlen($root)) : $f['file']).'('.$f['line'].'): '.$f['call'],
                array_slice($app, 0, $max),
            ),
            'total' => count($parsed),
            'app' => count($app),
        ];
    }

    /**
     * The project root: the directory Composer's vendor dir sits in, when the
     * trace has framework frames that agree on one.
     *
     * @param  list<string>  $files
     */
    private static function root(array $files): string
    {
        $roots = [];

        foreach ($files as $file) {
            if (str_starts_with($file, '/') && ($at = strpos($file, '/vendor/')) !== false) {
                $roots[substr($file, 0, $at + 1)] = true;
            }
        }

        return count($roots) === 1 ? (string) array_key_first($roots) : '';
    }
}
