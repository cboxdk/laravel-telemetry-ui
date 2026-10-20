<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Connectors;

use Closure;
use Fiber;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;
use WeakMap;

/**
 * Independent backend reads, sent at the same time instead of one after the
 * other.
 *
 * A panel that needs six queries took the sum of their times, and could run
 * far past the connection timeout without any one query timing out. Here each
 * read runs in its own Fiber. When it reaches {@see ApiClient}, the request is
 * handed to the round in progress instead of sent; once every read is waiting
 * or done, the waiting requests go out as one Http::pool and each read resumes
 * with its own answer. A read that makes several requests in turn (a sliced
 * window, a fallback) waits again in the next round.
 *
 * Drivers need nothing for this: any read that goes through ApiClient joins
 * in, and anything else (a custom driver with its own client) just runs.
 *
 * @internal Panels reach it through Panel::later() and Panel::settle().
 */
final class Together
{
    /**
     * Requests in flight per round when `telemetry-ui.concurrency` is not
     * set. A page loads its panels at the same time as well, and a backend
     * queues what it cannot run.
     */
    public const CONCURRENCY = 2;

    /** @var WeakMap<Fiber<mixed, mixed, mixed, mixed>, true>|null */
    private static ?WeakMap $fibers = null;

    /** Whether the code running now is a read inside {@see run()}. */
    public static function inside(): bool
    {
        $fiber = Fiber::getCurrent();

        return $fiber !== null && self::$fibers !== null && isset(self::$fibers[$fiber]);
    }

    /**
     * Hand one request to the round in progress and wait for its answer: the
     * response, or the exception the pool caught for it (a ConnectionException
     * on a timeout).
     *
     * @param  Closure(PendingRequest): mixed  $send  configures and sends the request on the pool's async PendingRequest
     */
    public static function send(Closure $send): Response|Throwable
    {
        $answer = Fiber::suspend($send);

        return $answer instanceof Response || $answer instanceof Throwable
            ? $answer
            : new RuntimeException('A read was resumed without an answer.');
    }

    /**
     * Run the reads together. Each result is what its read returned, or the
     * exception it threw; keys are kept.
     *
     * @template TKey of array-key
     * @template TValue
     *
     * @param  array<TKey, callable(): TValue>  $reads
     * @return array<TKey, TValue|Throwable>
     */
    public static function run(array $reads): array
    {
        // Already inside a read (a panel helper that settles its own reads),
        // nothing to overlap, or turned off: run them as they are.
        if (self::inside() || count($reads) < 2 || self::concurrency() < 2) {
            $results = [];

            foreach ($reads as $key => $read) {
                try {
                    $results[$key] = $read();
                } catch (Throwable $exception) {
                    $results[$key] = $exception;
                }
            }

            return $results;
        }

        $fibers = self::$fibers ??= new WeakMap;

        /** @var array<TKey, TValue|Throwable> $results */
        $results = [];
        /** @var array<TKey, array{Fiber<mixed, mixed, mixed, mixed>, Closure(PendingRequest): mixed}> $waiting */
        $waiting = [];

        foreach ($reads as $key => $read) {
            $fiber = new Fiber($read);
            $fibers[$fiber] = true;
            self::advance($key, $fiber, static fn (): mixed => $fiber->start(), $results, $waiting);
        }

        while ($waiting !== []) {
            $round = array_slice($waiting, 0, self::concurrency(), preserve_keys: true);
            $waiting = array_diff_key($waiting, $round);

            $answers = Http::pool(static function (Pool $pool) use ($round): void {
                foreach ($round as $key => [, $send]) {
                    $send($pool->as((string) $key));
                }
            });

            foreach ($round as $key => [$fiber]) {
                $answer = $answers[$key] ?? new RuntimeException('The pool returned no answer for a read.');
                self::advance($key, $fiber, static fn (): mixed => $fiber->resume($answer), $results, $waiting);
            }
        }

        // In the order the reads were given.
        $ordered = [];

        foreach (array_keys($reads) as $key) {
            if (array_key_exists($key, $results)) {
                $ordered[$key] = $results[$key];
            }
        }

        return $ordered;
    }

    /** `telemetry-ui.concurrency`: requests in flight at once; 1 sends them one after the other. */
    private static function concurrency(): int
    {
        $value = function_exists('config') ? config('telemetry-ui.concurrency', self::CONCURRENCY) : self::CONCURRENCY;

        return is_numeric($value) ? max(1, (int) $value) : self::CONCURRENCY;
    }

    /**
     * Start or resume one read and file where it ended up: done, failed, or
     * waiting on a request.
     *
     * @template TKey of array-key
     * @template TValue
     *
     * @param  TKey  $key
     * @param  Fiber<mixed, mixed, mixed, mixed>  $fiber
     * @param  Closure(): mixed  $step
     * @param  array<TKey, TValue|Throwable>  $results
     * @param  array<TKey, array{Fiber<mixed, mixed, mixed, mixed>, Closure(PendingRequest): mixed}>  $waiting
     */
    private static function advance(int|string $key, Fiber $fiber, Closure $step, array &$results, array &$waiting): void
    {
        try {
            $suspended = $step();
        } catch (Throwable $exception) {
            $results[$key] = $exception;

            return;
        }

        if ($fiber->isTerminated()) {
            /** @var TValue $value */
            $value = $fiber->getReturn();
            $results[$key] = $value;

            return;
        }

        if (! $suspended instanceof Closure) {
            // Something else in the read suspended its fiber: not ours to answer.
            $results[$key] = new RuntimeException('A read suspended for something other than a backend request.');

            return;
        }

        /** @var Closure(PendingRequest): mixed $suspended */
        $waiting[$key] = [$fiber, $suspended];
    }
}
