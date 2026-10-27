<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Support;

use Cbox\TelemetryUi\TelemetryUiManager;
use Closure;
use Illuminate\Support\Facades\Auth;

/**
 * Request-scoped memo of the per-request connection switcher
 * ({@see TelemetryUiManager::connectionsUsing()}): the host's resolver runs
 * once per request, for the viewer of that request, and its answer is
 * forgotten with the request — so under a persistent runtime one viewer's
 * profiles never show up in the next viewer's header.
 */
class ResolvedConnectionOptions
{
    /** @var array<string, ConnectionOption>|null */
    private ?array $options = null;

    private bool $currentResolved = false;

    private ?string $current = null;

    /**
     * The options the resolver returns for this request's viewer, keyed by
     * value (a repeated value replaces the earlier one, as with
     * {@see TelemetryUiManager::connection()}).
     *
     * @param  Closure(mixed): iterable<ConnectionOption>  $resolver
     * @return array<string, ConnectionOption>
     */
    public function options(Closure $resolver): array
    {
        if ($this->options !== null) {
            return $this->options;
        }

        $options = [];

        foreach ($resolver(Auth::user()) as $option) {
            $options[$option->value] = $option;
        }

        return $this->options = $options;
    }

    /**
     * @param  Closure(mixed): ?string  $resolver
     */
    public function current(Closure $resolver): ?string
    {
        if (! $this->currentResolved) {
            $this->current = $resolver(Auth::user());
            $this->currentResolved = true;
        }

        return $this->current;
    }
}
