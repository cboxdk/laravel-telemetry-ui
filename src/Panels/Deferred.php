<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Panels;

use Closure;
use LogicException;

/**
 * One read a panel has asked for with {@see Panel::later()}: its value once
 * {@see Panel::settle()} has run, or null when it failed or was skipped.
 *
 * @template T
 */
final class Deferred
{
    private bool $settled = false;

    /** @var T|null */
    private mixed $value = null;

    /**
     * @param  Closure(): T  $read
     */
    public function __construct(public readonly Closure $read) {}

    /**
     * @param  T|null  $value
     *
     * @internal Set by Panel::settle().
     */
    public function resolve(mixed $value): void
    {
        $this->value = $value;
        $this->settled = true;
    }

    /**
     * @return T|null
     */
    public function value(): mixed
    {
        if (! $this->settled) {
            throw new LogicException('Read before settle(): call $this->settle() once every later() read is asked for.');
        }

        return $this->value;
    }
}
