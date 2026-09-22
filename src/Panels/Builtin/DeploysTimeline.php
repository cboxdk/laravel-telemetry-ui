<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Panels\Builtin;

use Cbox\TelemetryUi\Panels\Panel;
use DateTimeImmutable;

/**
 * Recent deploys within the active range — the list behind the deploy
 * annotation lines. Each links to the marker's own trace.
 */
final class DeploysTimeline extends Panel
{
    public function data(): array
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
