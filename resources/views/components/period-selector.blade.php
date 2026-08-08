{{-- Every control here reads the SHARED view state, not the raw query string:
     the window may have come from the URL or from what the reader last chose,
     and the header must show whichever one the cards are actually querying. --}}
@php($state = app(Cbox\TelemetryUi\Support\ViewState::class))
@php($current = $state->period())
@php($hasCustomRange = $state->hasCustomRange())

<div class="tui-header-controls">
    {{-- Copy deep-link to the current view (filters, range, scope).
         The link PINS the state into the URL rather than copying the address
         bar as-is: the range now survives in a cookie, so a bare copied link
         would silently retarget to whatever range the recipient last used. --}}
    <button type="button" class="tui-btn tui-copy-link" x-data="telemetryUiCopyLink(@js($state->queryParams()))" x-on:click="copy()"
            :class="{ 'is-copied': copied }" title="Copy a link to this exact view">
        <span x-show="!copied">🔗 Copy link</span>
        <span x-show="copied" x-cloak>✓ Copied</span>
    </button>

    {{-- Refresh now: re-runs the cards in place instead of reloading the page,
         so an open drawer, a scroll position and an in-flight selection all
         survive the refresh — and it costs one round trip, not a full render.
         Falls back to a reload if Livewire hasn't booted. --}}
    <button type="button" class="tui-btn tui-refresh-now" x-data
            x-on:click="window.Livewire ? window.Livewire.dispatch('telemetry-ui:refresh') : window.location.reload()"
            title="Refresh now">↻</button>

    {{-- Auto refresh. The selected interval is SERVER-rendered from the shared
         state and handed to Alpine as the same number, so the control's label
         and the timer that is actually running cannot disagree — they have one
         source. (They used to: the timer was restored from sessionStorage while
         the combobox labelled itself from the DOM's selected option, which the
         server always drew as "off".) --}}
    <div class="tui-refresh" x-data="telemetryUiRefresh({{ $state->refresh() }}, @js($state->cookieAttributes()))" title="Auto refresh">
        <x-telemetry-ui::combobox x-model="value" x-on:change="apply()">
            @foreach (Cbox\TelemetryUi\Support\ViewState::INTERVALS as $seconds)
                <option value="{{ $seconds }}" @selected($seconds === $state->refresh())>⟳ {{ $seconds === 0 ? 'off' : $seconds.'s' }}</option>
            @endforeach
        </x-telemetry-ui::combobox>
    </div>

    {{-- Chart annotations: hide noisy marker types per type (ann_off csv).
         Pure frontend: updates the URL via replaceState and fires a window
         event the charts filter their marker lines on — no reload, no
         backend refetch. --}}
    @php($annMarkers = (array) config('telemetry-ui.annotations.markers', []))
    @if ((bool) config('telemetry-ui.annotations.enabled', true) && $annMarkers !== [])
        <div class="tui-range" title="Chart annotations"
             x-data="{
                 open: false,
                 keys: {{ \Illuminate\Support\Js::from(array_keys($annMarkers)) }},
                 off: (new URL(window.location).searchParams.get('ann_off') || '').split(',').filter(Boolean),
                 init() {
                     // Charts resolve marker keys → event kinds through this map.
                     window.telemetryUiAnnEvents = {{ \Illuminate\Support\Js::from(array_map(static fn (array $m): string => $m['event'] ?? '', $annMarkers)) }};
                 },
                 apply(off) {
                     this.off = off;
                     const url = new URL(window.location);
                     if (off.length) { url.searchParams.set('ann_off', off.join(',')); } else { url.searchParams.delete('ann_off'); }
                     history.replaceState(history.state, '', url);
                     const kinds = off.map(k => window.telemetryUiAnnEvents[k]).filter(Boolean);
                     window.dispatchEvent(new CustomEvent('telemetry-ui:annotations-visibility', { detail: { kinds } }));
                 },
                 toggle(key) {
                     this.apply(this.off.includes(key) ? this.off.filter(k => k !== key) : [...this.off, key]);
                 }
             }">
            <button type="button" class="tui-btn" :class="{ 'is-range-active': off.length }" x-on:click="open = !open">
                ⚑<span x-show="off.length" x-cloak x-text="' ' + off.length + ' off'"></span>
            </button>
            <div class="tui-range-panel" x-show="open" x-cloak x-on:click.outside="open = false">
                {{-- Master switch: hide every type at once, or bring them all back. --}}
                <label style="display: flex; justify-content: flex-start; gap: 8px; align-items: center; cursor: pointer; white-space: nowrap; padding-bottom: 6px; margin-bottom: 6px; border-bottom: 1px solid rgba(255, 255, 255, 0.08);">
                    <input type="checkbox" :checked="off.length === 0" x-on:change="apply(off.length === 0 ? [...keys] : [])">
                    {{-- spacer to align the header label with the swatched rows below --}}
                    <span style="flex: none; width: 3px; height: 12px;"></span>
                    <strong>All annotations</strong>
                </label>
                @foreach ($annMarkers as $annKey => $annMarker)
                    <label style="display: flex; justify-content: flex-start; gap: 8px; align-items: center; cursor: pointer; white-space: nowrap;">
                        <input type="checkbox" :checked="!off.includes('{{ $annKey }}')" x-on:change="toggle('{{ $annKey }}')">
                        <span style="flex: none; width: 3px; height: 12px; border-radius: 2px; background: {{ $annMarker['color'] ?? '#c084fc' }};"></span>
                        <span>{{ $annMarker['label'] ?? $annKey }}</span>
                    </label>
                @endforeach
            </div>
        </div>
    @endif

    {{-- Reset zoom: only shown while a custom (zoomed/absolute) range is active.
         It pins the preset period explicitly — the range is resolved as one
         unit, so a URL with neither period nor from/to would simply fall back
         to the remembered custom range and the button would do nothing. --}}
    @if ($hasCustomRange)
        <button type="button" class="tui-btn tui-reset-zoom" title="Reset zoom"
            x-data
            x-on:click="
                const url = new URL(window.location);
                url.searchParams.delete('from');
                url.searchParams.delete('to');
                url.searchParams.set('period', '{{ $current->value }}');
                window.location = url;
            ">↺ Reset</button>
    @endif

    {{-- Custom absolute range --}}
    <div class="tui-range" x-data="telemetryUiRange(@js($state->from()), @js($state->to()))">
        <button type="button" class="tui-btn {{ $hasCustomRange ? 'is-range-active' : '' }}" x-on:click="open = !open">
            @if ($hasCustomRange)
                {{ Cbox\TelemetryUi\Support\TimeExpression::label($state->from()) }} – {{ Cbox\TelemetryUi\Support\TimeExpression::label($state->to()) }}
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

    {{-- Presets. Setting `period` and dropping `from`/`to` is what tells the
         server "this is a fresh range" — see the one-unit rule in ViewState. --}}
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
