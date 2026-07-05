@php($current = Cbox\TelemetryUi\Support\Period::tryFrom((string) request('period')) ?? Cbox\TelemetryUi\Support\Period::default())
@php($hasCustomRange = ctype_digit((string) request('from')) && ctype_digit((string) request('to')))

<div class="tui-header-controls">
    {{-- Copy deep-link to the current view (filters, range, scope) --}}
    <button type="button" class="tui-btn tui-copy-link" x-data="telemetryUiCopyLink()" x-on:click="copy()"
            :class="{ 'is-copied': copied }" title="Copy a link to this exact view">
        <span x-show="!copied">🔗 Copy link</span>
        <span x-show="copied" x-cloak>✓ Copied</span>
    </button>

    {{-- Refresh now --}}
    <button type="button" class="tui-btn tui-refresh-now" x-data x-on:click="window.location.reload()"
            title="Refresh now">↻</button>

    {{-- Auto refresh --}}
    <div class="tui-refresh" x-data="telemetryUiRefresh()" title="Auto refresh">
        <select x-model="value" x-on:change="apply()">
            <option value="0">⟳ off</option>
            <option value="10">⟳ 10s</option>
            <option value="30">⟳ 30s</option>
            <option value="60">⟳ 60s</option>
        </select>
    </div>

    {{-- Reset zoom: only shown while a custom (zoomed/absolute) range is active --}}
    @if ($hasCustomRange)
        <button type="button" class="tui-btn tui-reset-zoom" title="Reset zoom"
            x-data
            x-on:click="
                const url = new URL(window.location);
                url.searchParams.delete('from');
                url.searchParams.delete('to');
                window.location = url;
            ">↺ Reset</button>
    @endif

    {{-- Custom absolute range --}}
    <div class="tui-range" x-data="telemetryUiRange()">
        <button type="button" class="tui-btn {{ $hasCustomRange ? 'is-range-active' : '' }}" x-on:click="open = !open">
            @if ($hasCustomRange)
                {{ date('d/m H:i', (int) request('from')) }} – {{ date('d/m H:i', (int) request('to')) }}
            @else
                Custom
            @endif
        </button>
        <div class="tui-range-panel" x-show="open" x-cloak x-on:click.outside="open = false">
            <label>From <input type="datetime-local" x-model="from"></label>
            <label>To <input type="datetime-local" x-model="to"></label>
            <button type="button" class="tui-btn" x-on:click="apply()">Apply</button>
        </div>
    </div>

    {{-- Presets --}}
    <div class="tui-periods" role="tablist" aria-label="Time period">
        @foreach (Cbox\TelemetryUi\Support\Period::cases() as $period)
            <button
                type="button"
                class="tui-period {{ $period === $current && ! $hasCustomRange ? 'is-active' : '' }}"
                x-data
                x-on:click="
                    const url = new URL(window.location);
                    url.searchParams.set('period', '{{ $period->value }}');
                    url.searchParams.delete('from');
                    url.searchParams.delete('to');
                    window.location = url;
                "
            >{{ $period->label() }}</button>
        @endforeach
    </div>
</div>
