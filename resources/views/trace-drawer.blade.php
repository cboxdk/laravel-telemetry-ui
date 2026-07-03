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
                                <span class="tui-drawer-crumb {{ $loop->last ? 'is-current' : '' }}">{{ $crumb['type'] === 'issue' ? '⧉' : '⇄' }} {{ $crumb['label'] }}</span>@if (! $loop->last)<span class="tui-drawer-crumb-sep">›</span>@endif
                            @endforeach
                        </div>
                    @else
                        <span class="tui-drawer-eyebrow">{{ $mode === 'compose' ? 'New ticket' : ($mode === 'issue' ? 'Issue' : 'Trace') }}</span>
                    @endif
                    <h2>
                        @if ($mode === 'compose')
                            Create ticket @if ($trackerLabel !== '')<span class="tui-drawer-sub">· {{ $trackerLabel }}</span>@endif
                        @elseif ($mode === 'issue')
                            {{ $issue?->title ?? ($error ? 'Error' : 'Loading…') }}
                        @else
                            {{ $trace?->root()?->name ?: ($error ? 'Error' : 'Loading…') }}
                        @endif
                    </h2>
                </div>
                <div class="tui-drawer-actions">
                    @if ($fullUrl)
                        <a class="tui-btn" href="{{ $fullUrl }}" @if ($mode === 'issue') target="_blank" rel="noopener" @endif title="{{ $mode === 'issue' ? 'Open on tracker' : 'Open full page' }}">↗ {{ $mode === 'issue' ? 'Open' : 'Full page' }}</a>
                    @endif
                    <button type="button" class="tui-btn" wire:click="close" title="Close">✕</button>
                </div>
            </header>

            <div class="tui-drawer-body" wire:key="drawer-{{ $mode }}-{{ $key }}">
                @if ($open)
                    @if ($mode === 'compose')
                        @include('telemetry-ui::partials.compose-ticket', ['error' => $composeError])
                    @elseif ($mode === 'issue')
                        @include('telemetry-ui::partials.issue-detail', ['issue' => $issue, 'error' => $error])
                    @else
                        @include('telemetry-ui::partials.trace-detail', ['trace' => $trace, 'rows' => $rows, 'chain' => $chain, 'identities' => $identities, 'error' => $error])
                    @endif
                @endif
            </div>
        </aside>
    </div>
</div>
