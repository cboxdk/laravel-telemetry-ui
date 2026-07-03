<div>
    <div @class(['tui-drawer-root', 'is-open' => $open])>
        <div class="tui-drawer-overlay" wire:click="close"></div>
        <aside class="tui-drawer" x-on:keydown.escape.window="$wire.close()">
            <header class="tui-drawer-header">
                <div class="tui-drawer-title">
                    <span class="tui-drawer-eyebrow">Trace</span>
                    <h2>{{ $trace?->root()?->name ?: ($error ? 'Error' : 'Loading…') }}</h2>
                </div>
                <div class="tui-drawer-actions">
                    @if ($fullUrl)
                        <a class="tui-btn" href="{{ $fullUrl }}" title="Open full page">↗ Full page</a>
                    @endif
                    <button type="button" class="tui-btn" wire:click="close" title="Close">✕</button>
                </div>
            </header>

            <div class="tui-drawer-body" wire:key="drawer-{{ $traceId }}">
                @if ($open)
                    @include('telemetry-ui::partials.trace-detail', ['trace' => $trace, 'rows' => $rows, 'chain' => $chain, 'identities' => $identities, 'error' => $error])
                @endif
            </div>
        </aside>
    </div>
</div>
