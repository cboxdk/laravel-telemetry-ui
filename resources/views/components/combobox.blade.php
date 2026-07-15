@props(['searchable' => true])

{{--
    Searchable Combobox (Cbox — no native <select>). Wraps a hidden native
    <select> that keeps ALL forwarded bindings (wire:model.live, x-model, name,
    form navigation); the visible button + popover just drive it via native
    input/change events. Usage mirrors a select:

        <x-telemetry-ui::combobox wire:model.live="minMs" style="min-width:110px">
            <option value="">All</option>
            <option value="50">50 ms</option>
        </x-telemetry-ui::combobox>
--}}
<div class="tui-combobox" x-data="telemetryUiCombobox()" x-init="cbInit($refs.cbNative)"
     x-on:keydown.escape.stop="cbClose()" x-on:click.outside="cbClose()">

    <select x-ref="cbNative" class="tui-combobox-native" tabindex="-1" aria-hidden="true"
            {{ $attributes->except(['class', 'style']) }}>{{ $slot }}</select>

    <button type="button" class="tui-combobox-btn" style="{{ $attributes->get('style') }}"
            x-on:click="cbToggle()" x-bind:aria-expanded="cbOpen ? 'true' : 'false'">
        <span class="tui-combobox-val" x-text="cbLabel"></span>
        <svg class="tui-combobox-chev" width="12" height="12" viewBox="0 0 24 24" fill="none"
             stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg>
    </button>

    <div class="tui-combobox-pop" x-show="cbOpen" x-cloak x-transition.opacity.duration.100ms>
        @if ($searchable)
            <div class="tui-combobox-searchwrap">
                {{-- .stop keeps the search field's own input/change from bubbling to
                     a parent that navigates on change (scope-switcher) or to Livewire. --}}
                <input class="tui-combobox-search" type="text" placeholder="Search…" x-ref="cbSearch" x-model="cbQuery"
                       x-on:input.stop x-on:change.stop
                       x-on:keydown.down.prevent="cbMove(1)" x-on:keydown.up.prevent="cbMove(-1)"
                       x-on:keydown.enter.prevent="cbEnter()" x-on:keydown.escape.stop="cbClose()">
            </div>
        @endif
        <div class="tui-combobox-list">
            <template x-for="(o, i) in cbFiltered" :key="o.value + '-' + i">
                <button type="button" class="tui-combobox-opt"
                        x-bind:class="{ 'is-cursor': i === cbCursor, 'is-on': o.value === cbValue }"
                        x-on:mouseenter="cbCursor = i" x-on:click="cbPick(o)">
                    <span x-text="o.label"></span>
                    <svg x-show="o.value === cbValue" class="tui-combobox-check" width="13" height="13" viewBox="0 0 24 24"
                         fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>
                </button>
            </template>
            <div class="tui-combobox-empty" x-show="cbFiltered.length === 0">No matches</div>
        </div>
    </div>
</div>
