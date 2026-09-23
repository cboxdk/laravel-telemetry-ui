<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Panels\Builtin\Detail;

use Cbox\TelemetryUi\Panels\Attributes\Param;

/**
 * Scopes a card to a single exception class (the `?exception=` on the
 * exception-detail page).
 */
trait ScopesToException
{
    #[Param('exception')]
    public string $exception = '';

    protected function scopeMatchers(): string
    {
        return $this->exception === '' ? '' : 'exception="'.addcslashes($this->exception, '"\\').'"';
    }
}
