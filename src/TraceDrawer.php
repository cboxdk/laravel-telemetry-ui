<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi;

use Cbox\TelemetryUi\Connectors\ConnectionManager;
use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Support\TraceView;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * A single, layout-level Livewire component that slides a trace waterfall in
 * from the right without leaving the current page. Trace links dispatch
 * `telemetry-ui:open-trace`; the id is mirrored to the URL (?trace=) so the
 * view is shareable and the browser back button closes it.
 */
final class TraceDrawer extends Component
{
    #[Url(as: 'trace', except: '')]
    public string $traceId = '';

    public function mount(): void
    {
        // Deep-linked ?trace=<id> opens the drawer on load.
    }

    #[On('telemetry-ui:open-trace')]
    public function openTrace(string $traceId): void
    {
        $this->traceId = $traceId;
    }

    public function close(): void
    {
        $this->traceId = '';
    }

    public function render(): View
    {
        $open = $this->traceId !== '';

        $trace = null;
        $error = null;

        if ($open) {
            try {
                $trace = app(ConnectionManager::class)->traces()->trace($this->traceId);
            } catch (SourceException $exception) {
                $error = $exception->getMessage();
            }
        }

        /** @var view-string $view */
        $view = 'telemetry-ui::trace-drawer';

        return view($view, [
            'open' => $open,
            'trace' => $trace,
            'error' => $error,
            'rows' => $trace !== null ? TraceView::waterfall($trace) : [],
            'chain' => $trace !== null ? TraceView::chain($trace) : [],
            'identities' => $trace !== null ? TraceView::identities($trace) : [],
            'fullUrl' => $open ? route('telemetry-ui.trace', ['traceId' => $this->traceId]) : null,
        ]);
    }
}
