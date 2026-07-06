@if ($error !== null)
    <div class="tui-error">{{ $error }}</div>
@elseif ($issue === null)
    <div class="tui-empty">Issue not found.</div>
@else
    <div class="tui-issue-meta">
        <span class="tui-badge {{ $issue->kind === 'pr' ? 'tui-badge-info' : ($issue->isOpen() ? 'tui-badge-ok' : '') }}">
            {{ $issue->kind === 'pr' ? 'PR '.$issue->id : $issue->id }}
        </span>
        <span class="tui-badge {{ $issue->isOpen() ? 'tui-badge-ok' : '' }}">{{ ucfirst($issue->state) }}</span>
        @foreach ($issue->labels as $label)
            <span class="tui-badge">{{ $label }}</span>
        @endforeach
    </div>

    <div class="tui-issue-facts">
        @if ($issue->author)<span><em>author</em> {{ $issue->author }}</span>@endif
        @if ($issue->assignee)<span><em>assignee</em> {{ $issue->assignee }}</span>@endif
        @if ($issue->count !== null)<span><em>comments</em> {{ $issue->count }}</span>@endif
        @if ($issue->updatedAt)<span><em>updated</em> {{ $issue->updatedAt->format('d/m H:i') }}</span>@endif
    </div>

    @php($traceIds = $issue->traceIds())
    @if ($traceIds !== [])
        <div class="tui-issue-relations">
            <span class="tui-chain-label">Referenced traces</span>
            @foreach ($traceIds as $tid)
                <a class="tui-chip tui-trace-link" data-trace-id="{{ $tid }}" href="{{ route('telemetry-ui.trace', ['traceId' => $tid]) }}">⇄ {{ substr($tid, 0, 12) }}…</a>
            @endforeach
        </div>
    @endif

    @if ($issue->body)
        {{-- Markdown → strict-allowlist HTML (SafeHtml): readable like on
             the tracker, never executable — external content. --}}
        <div class="tui-issue-body tui-md">{!! Cbox\TelemetryUi\Support\SafeHtml::fromMarkdown(Str::limit($issue->body, 8000)) !!}</div>
    @else
        <div class="tui-note">No description.</div>
    @endif
@endif
