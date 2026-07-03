<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Cards\Builtin;

use Cbox\TelemetryUi\Cards\Card;
use DateTimeImmutable;
use Illuminate\Contracts\View\View;

/**
 * Recent deploys within the active range — the list behind the deploy
 * annotation lines. Each links to the marker's own trace.
 */
final class DeploysTimeline extends Card
{
    public function render(): View
    {
        $annotations = $this->annotations();

        /** @var view-string $view */
        $view = 'telemetry-ui::cards.deploys-timeline';

        return view($view, [
            'deploys' => $annotations,
            'now' => new DateTimeImmutable,
        ]);
    }

    public function traceUrl(string $traceId): string
    {
        return route('telemetry-ui.trace', ['traceId' => $traceId]);
    }
}
