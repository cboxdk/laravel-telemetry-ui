<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Tests\Fixtures;

use Cbox\TelemetryUi\Cards\Card;
use Illuminate\Contracts\View\View;

final class DummyCard extends Card
{
    public function render(): View
    {
        return view('telemetry-ui::cards.chart', [
            'title' => 'Dummy',
            'series' => [],
            'stats' => [],
            'type' => 'line',
            'unit' => null,
            'error' => null,
            'span' => 1,
            'note' => null,
            'height' => 200,
        ]);
    }
}
