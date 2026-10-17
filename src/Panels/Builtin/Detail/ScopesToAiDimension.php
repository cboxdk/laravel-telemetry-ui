<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Panels\Builtin\Detail;

use Cbox\TelemetryUi\Panels\Attributes\Param;

/**
 * Scopes a card to one value of a context dimension — a user, a team, a
 * tenant, a client IP — on that value's entity page. The entity page passes
 * the dimension's attribute as `_dim` and the value as `ai_value`.
 */
trait ScopesToAiDimension
{
    #[Param('_dim')]
    public string $dimension = '';

    #[Param('ai_value')]
    public string $value = '';

    /**
     * @return array{string, string}|null
     */
    protected function scopedDimension(): ?array
    {
        return preg_match('/^[a-zA-Z0-9_.\-]+$/', $this->dimension) === 1 && $this->value !== ''
            ? [$this->dimension, $this->value]
            : null;
    }
}
