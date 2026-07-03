<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi;

use Cbox\TelemetryUi\Connectors\ConnectionManager;
use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Contracts\CreatesIssues;
use Cbox\TelemetryUi\Support\TraceView;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * A single, layout-level slide-in drawer that shows detail — a trace
 * waterfall or an issue — from the right without leaving the page.
 *
 * It is a *stack*: opening a trace from inside an issue (or vice versa)
 * pushes onto the drawer and a back button pops it, so you can dig deeper
 * and return without losing the context you came from. The top of the stack
 * mirrors to the URL (?trace= / ?issue=) so the current view is shareable and
 * the browser back button closes it.
 */
final class TraceDrawer extends Component
{
    /**
     * @var list<array{type: string, id: string}>
     */
    public array $stack = [];

    #[Url(as: 'trace', except: '')]
    public string $traceId = '';

    #[Url(as: 'issue', except: '')]
    public string $issueId = '';

    // Compose-ticket form state (transient, not URL-backed).
    public bool $composing = false;

    public string $draftTitle = '';

    public string $draftBody = '';

    /** @var list<string> */
    public array $draftLabels = [];

    public ?string $composeError = null;

    public function mount(): void
    {
        // Deep link: ?trace= / ?issue= seeds the stack on first load.
        if ($this->traceId !== '') {
            $this->stack = [['type' => 'trace', 'id' => $this->traceId]];
        } elseif ($this->issueId !== '') {
            $this->stack = [['type' => 'issue', 'id' => $this->issueId]];
        }
    }

    #[On('telemetry-ui:open-trace')]
    public function openTrace(string $traceId): void
    {
        $this->push('trace', $traceId);
    }

    #[On('telemetry-ui:open-issue')]
    public function openIssue(string $issueId): void
    {
        $this->push('issue', $issueId);
    }

    /**
     * @param  list<string>  $labels
     */
    #[On('telemetry-ui:compose-ticket')]
    public function composeTicket(string $title = '', string $body = '', array $labels = []): void
    {
        $this->draftTitle = $title;
        $this->draftBody = $body;
        $this->draftLabels = array_values(array_filter($labels, 'is_string'));
        $this->composeError = null;
        $this->composing = true;
    }

    public function submitTicket(): void
    {
        $this->composeError = null;

        $title = trim($this->draftTitle);

        if ($title === '') {
            $this->composeError = 'A title is required.';

            return;
        }

        $source = app(ConnectionManager::class)->issues();

        if (! $source instanceof CreatesIssues) {
            $this->composeError = 'The configured tracker cannot create issues.';

            return;
        }

        try {
            $issue = $source->createIssue($title, $this->draftBody, $this->draftLabels);
        } catch (SourceException $exception) {
            $this->composeError = $exception->getMessage();

            return;
        }

        // Land on the freshly created ticket.
        $this->composing = false;
        $this->push('issue', $issue->id);
    }

    public function cancelCompose(): void
    {
        $this->composing = false;
        $this->composeError = null;
    }

    public function back(): void
    {
        array_pop($this->stack);
        $this->syncUrl();
    }

    public function close(): void
    {
        $this->stack = [];
        $this->composing = false;
        $this->syncUrl();
    }

    private function push(string $type, string $id): void
    {
        $top = end($this->stack) ?: null;

        // Don't stack the same thing twice in a row.
        if (! ($top !== null && $top['type'] === $type && $top['id'] === $id)) {
            $this->stack[] = ['type' => $type, 'id' => $id];
        }

        $this->syncUrl();
    }

    private function syncUrl(): void
    {
        $top = end($this->stack) ?: null;

        $this->traceId = $top !== null && $top['type'] === 'trace' ? $top['id'] : '';
        $this->issueId = $top !== null && $top['type'] === 'issue' ? $top['id'] : '';
    }

    public function render(): View
    {
        if ($this->composing) {
            return $this->renderCompose();
        }

        $top = end($this->stack) ?: null;

        return $top !== null && $top['type'] === 'issue'
            ? $this->renderIssue($top['id'])
            : $this->renderTrace($top['type'] ?? null, $top['id'] ?? '');
    }

    private function renderCompose(): View
    {
        /** @var view-string $view */
        $view = 'telemetry-ui::trace-drawer';

        return view($view, [
            'mode' => 'compose',
            'open' => true,
            'depth' => count($this->stack),
            'crumbs' => $this->crumbs(),
            'key' => 'compose',
            'trackerLabel' => app(ConnectionManager::class)->hasIssues() ? app(ConnectionManager::class)->issues()->label() : '',
            'composeError' => $this->composeError,
            'fullUrl' => null,
        ]);
    }

    /**
     * @return array<int, array{type: string, id: string, label: string}>
     */
    private function crumbs(): array
    {
        return array_map(static fn (array $entry): array => [
            'type' => $entry['type'],
            'id' => $entry['id'],
            'label' => ($entry['type'] === 'issue' ? $entry['id'] : substr($entry['id'], 0, 8).'…'),
        ], $this->stack);
    }

    private function renderTrace(?string $type, string $traceId): View
    {
        $open = $type !== null && $traceId !== '';
        $trace = null;
        $error = null;

        if ($open) {
            try {
                $trace = app(ConnectionManager::class)->traces()->trace($traceId);
            } catch (SourceException $exception) {
                $error = $exception->getMessage();
            }
        }

        /** @var view-string $view */
        $view = 'telemetry-ui::trace-drawer';

        return view($view, [
            'mode' => 'trace',
            'open' => $open,
            'depth' => count($this->stack),
            'crumbs' => $this->crumbs(),
            'key' => $traceId,
            'trace' => $trace,
            'error' => $error,
            'rows' => $trace !== null ? TraceView::waterfall($trace) : [],
            'chain' => $trace !== null ? TraceView::chain($trace) : [],
            'identities' => $trace !== null ? TraceView::identities($trace) : [],
            'fullUrl' => $open ? route('telemetry-ui.trace', ['traceId' => $traceId]) : null,
        ]);
    }

    private function renderIssue(string $issueId): View
    {
        $issue = null;
        $error = null;

        try {
            $issue = app(ConnectionManager::class)->issues()->issue($issueId);
        } catch (SourceException $exception) {
            $error = $exception->getMessage();
        }

        /** @var view-string $view */
        $view = 'telemetry-ui::trace-drawer';

        return view($view, [
            'mode' => 'issue',
            'open' => true,
            'depth' => count($this->stack),
            'crumbs' => $this->crumbs(),
            'key' => $issueId,
            'issue' => $issue,
            'error' => $error,
            'fullUrl' => $issue?->url,
        ]);
    }
}
