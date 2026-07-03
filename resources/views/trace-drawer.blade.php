<div>
    <div @class(['tui-drawer-root', 'is-open' => $open])>
        <div class="tui-drawer-overlay" wire:click="close"></div>
        <aside class="tui-drawer" x-on:keydown.escape.window="$wire.close()">
            <header class="tui-drawer-header">
                <div class="tui-drawer-title">
                    <span class="tui-drawer-eyebrow">{{ $mode === 'issue' ? 'Issue' : 'Trace' }}</span>
                    <h2>
                        @if ($mode === 'issue')
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
                    @if ($mode === 'issue')
                        @include('telemetry-ui::partials.issue-detail', ['issue' => $issue, 'error' => $error])
                    @else
                        @include('telemetry-ui::partials.trace-detail', ['trace' => $trace, 'rows' => $rows, 'chain' => $chain, 'identities' => $identities, 'error' => $error])
                    @endif
                @endif
            </div>
        </aside>
    </div>
</div>
