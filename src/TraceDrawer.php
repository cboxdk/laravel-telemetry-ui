<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi;

use Cbox\TelemetryUi\Analysis\SignalContext;
use Cbox\TelemetryUi\Analysis\TraceProfile;
use Cbox\TelemetryUi\Cards\Concerns\ScopesQueries;
use Cbox\TelemetryUi\Connectors\ConnectionManager;
use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Contracts\CreatesIssues;
use Cbox\TelemetryUi\Support\ExceptionFingerprint;
use Cbox\TelemetryUi\Support\TraceView;
use DateTimeImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
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
     * The exception-group detail searches its own wide window (independent of
     * the page period) so "first seen" is meaningful, bounded by a sample cap.
     */
    private const EXCEPTION_LOOKBACK_DAYS = 30;

    private const EXCEPTION_SEARCH_LIMIT = 100;

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
            'fullUrl' => $open ? route('telemetry-ui.trace', ['traceId' => $traceId]) : null,
        ]);
    }

    /**
     * The Sentry-style error-group detail: every occurrence of one
     * `exception.group` fingerprint (bounded sample over a wide window), with
     * the newest occurrence's stacktrace + source context. Backend errors are
     * read from the structured exception records laravel-telemetry emits into
     * Loki (which carry the stacktrace); when the group has none, it falls
     * back to browser exception spans in Tempo (fingerprinted read-side, see
     * {@see ExceptionFingerprint}) — so the whole panel is read-side only.
     */
    private function renderException(string $group): View
    {
        $error = null;
        $occurrences = [];
        $stats = null;
        $detail = null;

        // Fingerprints are 12 hex chars, but custom emitters may stamp their
        // own — allow a token-ish id, never raw LogQL/TraceQL metacharacters.
        if (preg_match('#^[\w.:/-]{1,64}$#', $group) !== 1) {
            $error = 'Not a valid error-group id.';
        } else {
            try {
                $occurrences = $this->backendOccurrences($group);

                // A group is one throw site in one runtime — when Loki has no
                // records for it, it can only be a frontend group.
                if ($occurrences === []) {
                    $occurrences = $this->browserOccurrences($group);
                }

                $stats = $this->exceptionStats($occurrences);
                $detail = $occurrences[0]['detail'] ?? null;
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
            'stats' => $stats,
            'occurrences' => array_slice($occurrences, 0, 20),
            'detail' => $detail,
            'canCreate' => $canCreate,
            'draft' => $canCreate ? $this->exceptionDraft($group, $stats, $detail) : null,
            'lookbackDays' => self::EXCEPTION_LOOKBACK_DAYS,
            // No full-page equivalent: the occurrences table below IS the
            // all-occurrences view (backend groups aren't traceable by a
            // span attribute, so a traces-page link would come up empty).
            'fullUrl' => null,
        ]);
    }

    /**
     * Backend occurrences: the structured exception records laravel-telemetry
     * emits into Loki at report() time — authoritative (present even when the
     * trace was sampled away) and complete (they carry the stacktrace and
     * optional source context). Newest first.
     *
     * @return list<array{nano: int, at: string, traceId: string, service: string, message: string, frontend: bool, detail: array{type: string, message: string, file: string, line: int, stacktrace: string, source: string}}>
     */
    private function backendOccurrences(string $group): array
    {
        [$start, $end] = $this->exceptionWindow();

        $entries = app(ConnectionManager::class)->logs()->query(
            $this->logSelector().' | exception_group="'.$this->escapeLabelValue($group).'"',
            $start,
            $end,
            limit: self::EXCEPTION_SEARCH_LIMIT,
        );

        $occurrences = [];

        foreach ($entries as $entry) {
            if (($entry->labels['exception_group'] ?? '') !== $group) {
                continue;
            }

            $label = static fn (string $key): string => $entry->labels[$key] ?? '';

            $occurrences[] = [
                'nano' => $entry->timestampNano,
                'at' => Carbon::createFromTimestamp(intdiv($entry->timestampNano, 1_000_000_000))->format('d/m H:i:s'),
                'traceId' => $label('trace_id'),
                'service' => $label('service_name'),
                'message' => $label('exception_message'),
                'frontend' => false,
                'detail' => [
                    'type' => $label('exception_type'),
                    'message' => $label('exception_message'),
                    'file' => $label('exception_file'),
                    'line' => (int) $label('exception_line'),
                    'stacktrace' => $label('exception_stacktrace'),
                    'source' => $label('exception_source'),
                ],
            ];
        }

        usort($occurrences, static fn (array $a, array $b): int => $b['nano'] <=> $a['nano']);

        return $occurrences;
    }

    /**
     * Frontend occurrences: browser exception spans in Tempo. The ingest
     * doesn't stamp a fingerprint, so match on the one computed read-side
     * (same algorithm as the backend). Browser errors carry no stacktrace —
     * type/message/file:line is all the SDK ships. Newest first.
     *
     * @return list<array{nano: int, at: string, traceId: string, service: string, message: string, frontend: bool, detail: array{type: string, message: string, file: string, line: int, stacktrace: string, source: string}}>
     */
    private function browserOccurrences(string $group): array
    {
        [$start, $end] = $this->exceptionWindow();

        $traceql = '{ '.$this->traceScope('span.browser = true && span.exception.type != nil')
            .' } | select(span.exception.type, span.exception.message, span.exception.file, span.exception.line)';

        $results = app(ConnectionManager::class)->traces()
            ->search($traceql, $start, $end, limit: self::EXCEPTION_SEARCH_LIMIT);

        $occurrences = [];

        foreach ($results as $summary) {
            foreach ($summary->matchedSpans as $span) {
                $attr = static fn (string $key): string => is_scalar($value = $span->attributes[$key] ?? null) ? (string) $value : '';

                $type = $attr('exception.type');
                $file = $attr('exception.file');
                $line = (int) $attr('exception.line');

                if ($type === '' || ExceptionFingerprint::compute($type, $file, $line) !== $group) {
                    continue;
                }

                $occurrences[] = [
                    'nano' => $span->startNano,
                    'at' => Carbon::createFromTimestamp(intdiv($span->startNano, 1_000_000_000))->format('d/m H:i:s'),
                    'traceId' => $summary->traceId,
                    'service' => $summary->rootServiceName,
                    'message' => $attr('exception.message'),
                    'frontend' => true,
                    'detail' => [
                        'type' => $type,
                        'message' => $attr('exception.message'),
                        'file' => $file,
                        'line' => $line,
                        'stacktrace' => '',
                        'source' => '',
                    ],
                ];
            }
        }

        usort($occurrences, static fn (array $a, array $b): int => $b['nano'] <=> $a['nano']);

        return $occurrences;
    }

    /**
     * @return array{DateTimeImmutable, DateTimeImmutable}
     */
    private function exceptionWindow(): array
    {
        $end = new DateTimeImmutable;

        return [$end->modify('-'.self::EXCEPTION_LOOKBACK_DAYS.' days'), $end];
    }

    /**
     * @param  list<array{nano: int, at: string, traceId: string, service: string, message: string, frontend: bool, detail: array{type: string, message: string, file: string, line: int, stacktrace: string, source: string}}>  $occurrences
     * @return array{count: int, sampled: bool, firstSeen: string, lastSeen: string, source: string}|null
     */
    private function exceptionStats(array $occurrences): ?array
    {
        if ($occurrences === []) {
            return null;
        }

        return [
            'count' => count($occurrences),
            'sampled' => count($occurrences) >= self::EXCEPTION_SEARCH_LIMIT,
            'firstSeen' => Carbon::createFromTimestamp(intdiv($occurrences[array_key_last($occurrences)]['nano'], 1_000_000_000))->diffForHumans(),
            'lastSeen' => Carbon::createFromTimestamp(intdiv($occurrences[0]['nano'], 1_000_000_000))->diffForHumans(),
            'source' => $occurrences[0]['frontend'] ? 'frontend' : 'backend',
        ];
    }

    /**
     * Prefilled compose-ticket draft for this error group.
     *
     * @param  array{count: int, sampled: bool, firstSeen: string, lastSeen: string, source: string}|null  $stats
     * @param  array{type: string, message: string, file: string, line: int, stacktrace: string, source: string}|null  $detail
     * @return array{title: string, body: string, labels: list<string>}
     */
    private function exceptionDraft(string $group, ?array $stats, ?array $detail): array
    {
        $type = $detail['type'] ?? '';
        $title = trim(($type !== '' ? class_basename($type) : 'Error '.$group).': '.Str::limit($detail['message'] ?? '', 90));

        $lines = array_filter([
            $type !== '' ? '**'.$type.'**' : null,
            ($detail['message'] ?? '') !== '' ? $detail['message'] : null,
            '',
            '- group: `'.$group.'`',
            ($detail['file'] ?? '') !== '' ? '- at: `'.$detail['file'].':'.($detail['line'] ?? 0).'`' : null,
            $stats !== null ? '- occurrences: '.$stats['count'].($stats['sampled'] ? '+' : '').' (last '.self::EXCEPTION_LOOKBACK_DAYS.' days, '.$stats['source'].')' : null,
            $stats !== null ? '- first seen: '.$stats['firstSeen'].' · last seen: '.$stats['lastSeen'] : null,
            '',
            ($detail['stacktrace'] ?? '') !== '' ? "```\n".Str::limit($detail['stacktrace'], 2000)."\n```" : null,
        ], static fn (?string $line): bool => $line !== null);

        return [
            'title' => rtrim($title, ': '),
            'body' => implode("\n", $lines),
            'labels' => ['bug'],
        ];
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
