<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi;

use Cbox\TelemetryUi\Analysis\ErrorGroupReport;
use Cbox\TelemetryUi\Analysis\RequestReport;
use Cbox\TelemetryUi\Analysis\SignalContext;
use Cbox\TelemetryUi\Analysis\TraceLogs;
use Cbox\TelemetryUi\Analysis\TraceProfile;
use Cbox\TelemetryUi\Cards\Concerns\ScopesQueries;
use Cbox\TelemetryUi\Connectors\ConnectionManager;
use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Contracts\CreatesIssues;
use Cbox\TelemetryUi\Queries\Ir\TraceCondition;
use Cbox\TelemetryUi\Queries\Ir\TraceOp;
use Cbox\TelemetryUi\Support\TraceView;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * A single, layout-level slide-in drawer that shows detail — a trace
 * waterfall, an issue or an exception group — from the right without leaving
 * the page.
 *
 * It is a *stack*: opening a trace from inside an issue (or vice versa)
 * pushes onto the drawer and a back button pops it, so you can dig deeper
 * and return without losing the context you came from. The top of the stack
 * mirrors to the URL (?trace= / ?issue= / ?exception=) so the current view is
 * shareable and the browser back button closes it.
 */
final class TraceDrawer extends Component
{
    use ScopesQueries;

    /**
     * @var list<array{type: string, id: string}>
     */
    public array $stack = [];

    #[Url(as: 'trace', except: '')]
    public string $traceId = '';

    #[Url(as: 'issue', except: '')]
    public string $issueId = '';

    #[Url(as: 'exception', except: '')]
    public string $exceptionGroup = '';

    // The active scope, mirrored from the page URL so the exception search
    // stays inside the viewer's selection — and, via ScopesQueries, can never
    // widen past the tenancy lock.
    #[Url(as: 'service', except: '')]
    public string $service = '';

    #[Url(as: 'env', except: '')]
    public string $environment = '';

    // Compose-ticket form state (transient, not URL-backed).
    public bool $composing = false;

    public string $draftTitle = '';

    public string $draftBody = '';

    /** @var list<string> */
    public array $draftLabels = [];

    public ?string $composeError = null;

    public function mount(): void
    {
        // Deep link: ?trace= / ?issue= / ?exception= seeds the stack on first load.
        if ($this->traceId !== '') {
            $this->stack = [['type' => 'trace', 'id' => $this->traceId]];
        } elseif ($this->issueId !== '') {
            $this->stack = [['type' => 'issue', 'id' => $this->issueId]];
        } elseif ($this->exceptionGroup !== '') {
            $this->stack = [['type' => 'exception', 'id' => $this->exceptionGroup]];
        }
    }

    #[On('telemetry-ui:open-trace')]
    public function openTrace(string $traceId, bool $replace = false): void
    {
        $this->push('trace', $traceId, $replace);
    }

    #[On('telemetry-ui:open-issue')]
    public function openIssue(string $issueId, bool $replace = false): void
    {
        $this->push('issue', $issueId, $replace);
    }

    #[On('telemetry-ui:open-exception')]
    public function openException(string $group, bool $replace = false): void
    {
        $this->push('exception', $group, $replace);
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

        // Server-side enforcement of the write ability — the compose UI is
        // hidden without it, but never trust the client.
        if (! Gate::allows('manageTelemetryUi')) {
            $this->composeError = 'You are not authorized to create issues.';

            return;
        }

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

    /**
     * Push onto the stack, or — for a click on the PAGE while the pane is
     * already open — replace it: the docked pane behaves like a properties
     * panel (select a new row, see that row). Links inside the pane keep
     * stacking so back-navigation still works while digging.
     */
    private function push(string $type, string $id, bool $replace = false): void
    {
        if ($replace) {
            $this->stack = [['type' => $type, 'id' => $id]];
            $this->syncUrl();

            return;
        }

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
        $this->exceptionGroup = $top !== null && $top['type'] === 'exception' ? $top['id'] : '';
    }

    public function render(): View
    {
        if ($this->composing) {
            return $this->renderCompose();
        }

        $top = end($this->stack) ?: null;

        return match (true) {
            $top !== null && $top['type'] === 'issue' => $this->renderIssue($top['id']),
            $top !== null && $top['type'] === 'exception' => $this->renderException($top['id']),
            default => $this->renderTrace($top['type'] ?? null, $top['id'] ?? ''),
        };
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
            'label' => $entry['type'] === 'trace' ? substr($entry['id'], 0, 8).'…' : $entry['id'],
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
            'context' => $trace !== null ? app(SignalContext::class)->forTrace($trace) : [],
            'profile' => $trace !== null ? app(TraceProfile::class)->forTrace($trace) : [],
            'report' => $trace !== null ? RequestReport::from($trace) : null,
            'traceLogs' => $trace !== null ? app(TraceLogs::class)->forTrace($trace) : [],
            'fullUrl' => $open ? route('telemetry-ui.trace', ['traceId' => $traceId]) : null,
        ]);
    }

    /**
     * The Sentry-style error-group detail — data assembled by the shared
     * {@see ErrorGroupReport} (also behind the full issue page), scoped by
     * this component's selection + tenancy lock.
     */
    private function renderException(string $group): View
    {
        $error = null;
        $report = ['occurrences' => [], 'stats' => null, 'detail' => null, 'request' => null, 'suspect' => null, 'releases' => []];

        if (! ErrorGroupReport::validId($group)) {
            $error = 'Not a valid error-group id.';
        } else {
            try {
                $report = app(ErrorGroupReport::class)->for(
                    $group,
                    $this->logSelector(),
                    $this->traceQuery(
                        TraceCondition::token('span.browser', TraceOp::Eq, 'true'),
                        TraceCondition::nil('span.exception.type'),
                    ),
                );
            } catch (SourceException $exception) {
                $error = $exception->getMessage();
            }
        }

        $manager = app(ConnectionManager::class);
        $canCreate = Gate::allows('manageTelemetryUi')
            && $manager->hasIssues()
            && $manager->issues() instanceof CreatesIssues;

        /** @var view-string $view */
        $view = 'telemetry-ui::trace-drawer';

        return view($view, [
            'mode' => 'exception',
            'open' => true,
            'depth' => count($this->stack),
            'crumbs' => $this->crumbs(),
            'key' => $group,
            'error' => $error,
            'group' => $group,
            'stats' => $report['stats'],
            'occurrences' => array_slice($report['occurrences'], 0, 20),
            'detail' => $report['detail'],
            'request' => $report['request'],
            'suspect' => $report['suspect'],
            'releases' => $report['releases'],
            'canCreate' => $canCreate,
            'draft' => $canCreate ? app(ErrorGroupReport::class)->draft($group, $report['stats'], $report['detail']) : null,
            'lookbackDays' => ErrorGroupReport::LOOKBACK_DAYS,
            // Sentry-style full page for this issue.
            'fullUrl' => route('telemetry-ui.page', array_filter([
                'page' => 'error-detail',
                'group' => $group,
                'period' => request('period'),
            ])),
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
