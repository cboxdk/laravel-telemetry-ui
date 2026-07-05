<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Http\Controllers;

use Cbox\TelemetryUi\Analysis\SignalContext;
use Cbox\TelemetryUi\Connectors\ConnectionManager;
use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Events\DashboardViewed;
use Cbox\TelemetryUi\Support\Fleet;
use Cbox\TelemetryUi\Support\SchemaDetector;
use Cbox\TelemetryUi\Support\TraceView;
use Cbox\TelemetryUi\TelemetryUiManager;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

final class TraceController
{
    use BuildsChrome;

    public function __invoke(
        TelemetryUiManager $manager,
        SchemaDetector $detector,
        Fleet $fleet,
        ConnectionManager $connections,
        SignalContext $context,
        string $traceId,
    ): View {
        $trace = null;
        $error = null;

        try {
            $trace = $connections->traces()->trace($traceId);
        } catch (SourceException $exception) {
            $error = $exception->getMessage();
        }

        /** @var view-string $view */
        $view = 'telemetry-ui::trace';

        $pages = $this->accessiblePages($manager, $detector);
        $services = $fleet->services();
        $environments = $fleet->environments();

        event(new DashboardViewed(Auth::user(), 'traces', (string) Request::query('service', ''), (string) Request::query('env', '')));

        return view($view, [
            'pages' => $pages,
            'services' => $services,
            'environments' => $environments,
            'traceId' => $traceId,
            'trace' => $trace,
            'error' => $error,
            'rows' => $trace !== null ? TraceView::waterfall($trace) : [],
            'chain' => $trace !== null ? TraceView::chain($trace) : [],
            'identities' => $trace !== null ? TraceView::identities($trace) : [],
            'context' => $trace !== null ? $context->forTrace($trace) : [],
            ...$this->chrome($pages, $services, $environments, 'traces'),
        ]);
    }
}
