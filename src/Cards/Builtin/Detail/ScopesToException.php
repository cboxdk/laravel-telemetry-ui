<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Cards\Builtin\Detail;

use Livewire\Attributes\Url;

/**
 * Scopes a card to a single exception class (the `?exception=` on the
 * exception-detail page).
 */
trait ScopesToException
{
    #[Url(as: 'exception')]
    public string $exception = '';

    protected function scopeMatchers(): string
    {
        return $this->exception === '' ? '' : 'exception="'.addcslashes($this->exception, '"\\').'"';
    }
}
