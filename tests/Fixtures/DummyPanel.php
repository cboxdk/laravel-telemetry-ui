<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Tests\Fixtures;

use Cbox\TelemetryUi\Panels\Panel;

/**
 * A minimal registered-able panel for registry tests: an empty chart, no I/O.
 */
final class DummyPanel extends Panel
{
    public function data(): array
    {
        return $this->chartCard('Dummy', annotate: false);
    }
}
