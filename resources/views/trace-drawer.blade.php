<div>
    <div @class(['tui-drawer-root', 'is-open' => $open])>
        <div class="tui-drawer-overlay" wire:click="close"></div>
        <aside class="tui-drawer" x-on:keydown.escape.window="$wire.close()">
            <header class="tui-drawer-header">
                @if ($depth > 1)
                    <button type="button" class="tui-drawer-back" wire:click="back" title="Back">←</button>
                @endif
                <div class="tui-drawer-title">
                    @if ($depth > 1)
                        <div class="tui-drawer-crumbs">
                            @foreach ($crumbs as $crumb)
                                <span class="tui-drawer-crumb {{ $loop->last ? 'is-current' : '' }}">{{ match ($crumb['type']) { 'issue' => '⧉', 'exception' => '⚠', default => '⇄' } }} {{ $crumb['label'] }}</span>@if (! $loop->last)<span class="tui-drawer-crumb-sep">›</span>@endif
                            @endforeach
                        </div>
                    @else
                        <span class="tui-drawer-eyebrow">{{ match ($mode) { 'compose' => 'New ticket', 'issue' => 'Issue', 'exception' => 'Error group', default => 'Trace' } }}</span>
                    @endif
                    <h2>
                        @if ($mode === 'compose')
                            Create ticket @if ($trackerLabel !== '')<span class="tui-drawer-sub">· {{ $trackerLabel }}</span>@endif
                        @elseif ($mode === 'issue')
                            {{ $issue?->title ?? ($error ? 'Error' : 'Loading…') }}
                        @elseif ($mode === 'exception')
                            {{ ($detail['type'] ?? '') !== '' ? $detail['type'] : 'Error group '.$group }}
                        @else
                            {{ $trace?->root()?->name ?: ($error ? 'Error' : 'Loading…') }}
                        @endif
                    </h2>
                </div>
                <div class="tui-drawer-actions">
                    @if ($fullUrl)
                        <a class="tui-btn" href="{{ $fullUrl }}" @if ($mode === 'issue') target="_blank" rel="noopener" @endif title="{{ match ($mode) { 'issue' => 'Open on tracker', 'exception' => 'Open the full issue page', default => 'Open full page' } }}">↗ {{ $mode === 'issue' ? 'Open' : 'Full page' }}</a>
                    @endif
                    <button type="button" class="tui-btn" wire:click="close" title="Close">✕</button>
                </div>
            </header>

            <div class="tui-drawer-body" wire:key="drawer-{{ $mode }}-{{ $key }}">
                @if ($open)
                    @if ($mode === 'compose')
                        @can('manageTelemetryUi')
                            @include('telemetry-ui::partials.compose-ticket', ['error' => $composeError])
                        @else
                            <div class="tui-drawer-pad tui-tone-dim">You are not authorized to create tickets.</div>
                        @endcan
                    @elseif ($mode === 'issue')
                        @include('telemetry-ui::partials.issue-detail', ['issue' => $issue, 'error' => $error])
                    @elseif ($mode === 'exception')
                        @include('telemetry-ui::partials.exception-detail', ['error' => $error, 'group' => $group, 'stats' => $stats, 'occurrences' => $occurrences, 'detail' => $detail, 'request' => $request, 'suspect' => $suspect, 'releases' => $releases, 'canCreate' => $canCreate, 'draft' => $draft, 'lookbackDays' => $lookbackDays])
                    @else
                        @include('telemetry-ui::partials.trace-detail', ['trace' => $trace, 'rows' => $rows, 'chain' => $chain, 'identities' => $identities, 'error' => $error, 'context' => $context, 'profile' => $profile, 'report' => $report, 'traceLogs' => $traceLogs])
                    @endif
                @endif
            </div>
        </aside>
    </div>
</div>
