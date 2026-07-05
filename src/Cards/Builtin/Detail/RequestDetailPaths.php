<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Cards\Builtin\Detail;

use Cbox\TelemetryUi\Cards\Card;
use Cbox\TelemetryUi\Connectors\SourceException;
use Illuminate\Contracts\View\View;

/**
 * The concrete URLs behind a route pattern — `/{segments?}` is really
 * `/pricing`, `/blog/...`, and whatever bots probe. Read from the traces'
 * `url.path` span attribute; each drills to that exact path's traces.
 */
final class RequestDetailPaths extends Card
{
    use ScopesToRoute;

    public function render(): View
    {
        $paths = [];
        $error = null;

        if ($this->route !== '') {
            [$start, $end] = $this->range();

            try {
                $paths = $this->traces()->tagValues(
                    'span.url.path',
                    '{ '.$this->traceScope($this->routeTraceScope()).' }',
                    $start,
                    $end,
                    limit: 100,
                );
            } catch (SourceException $exception) {
                $error = $exception->getMessage();
            }
        }

        sort($paths);

        /** @var view-string $view */
        $view = 'telemetry-ui::cards.request-detail-paths';

        return view($view, ['paths' => array_slice($paths, 0, 60), 'error' => $error]);
    }

    public function tracesUrl(string $path): string
    {
        return $this->pageUrl('traces', [
            'q' => '{ '.$this->traceScope('span.url.path = "'.addcslashes($path, '"\\').'"').' }',
        ]);
    }
}
