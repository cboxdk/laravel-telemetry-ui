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
 * A single, layout-level slide-in drawer that shows detail — a trace
 * waterfall or an issue — from the right without leaving the current page.
 * Links dispatch `telemetry-ui:open-trace` / `telemetry-ui:open-issue`; the
 * id mirrors to the URL (?trace= / ?issue=) so the view is shareable and the
 * browser back button closes it.
 */
final class TraceDrawer extends Component
{
    #[Url(as: 'trace', except: '')]
    public string $traceId = '';

    #[Url(as: 'issue', except: '')]
    public string $issueId = '';

    #[On('telemetry-ui:open-trace')]
    public function openTrace(string $traceId): void
    {
        $this->traceId = $traceId;
        $this->issueId = '';
    }

    #[On('telemetry-ui:open-issue')]
    public function openIssue(string $issueId): void
    {
        $this->issueId = $issueId;
        $this->traceId = '';
    }

    public function close(): void
    {
        $this->traceId = '';
        $this->issueId = '';
    }

    public function render(): View
    {
        return $this->issueId !== '' ? $this->renderIssue() : $this->renderTrace();
    }

    private function renderTrace(): View
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
            'mode' => 'trace',
            'open' => $open,
            'key' => $this->traceId,
            'trace' => $trace,
            'error' => $error,
            'rows' => $trace !== null ? TraceView::waterfall($trace) : [],
            'chain' => $trace !== null ? TraceView::chain($trace) : [],
            'identities' => $trace !== null ? TraceView::identities($trace) : [],
            'fullUrl' => $open ? route('telemetry-ui.trace', ['traceId' => $this->traceId]) : null,
        ]);
    }

    private function renderIssue(): View
    {
        $issue = null;
        $error = null;

        try {
            $issue = app(ConnectionManager::class)->issues()->issue($this->issueId);
        } catch (SourceException $exception) {
            $error = $exception->getMessage();
        }

        /** @var view-string $view */
        $view = 'telemetry-ui::trace-drawer';

        return view($view, [
            'mode' => 'issue',
            'open' => true,
            'key' => $this->issueId,
            'issue' => $issue,
            'error' => $error,
            'fullUrl' => $issue?->url,
        ]);
    }
}
