@props(['connections' => [], 'current' => ''])

{{-- Backend profiles registered by the host with TelemetryUi::connection().
     Nothing registered means nothing rendered — a plain host sees no foreign
     chrome, exactly as with navLink().

     Deliberately a NATIVE <select>, not the searchable combobox the scope
     pickers use. This is the control that changes which backend you are looking
     at; the combobox is entirely Alpine-driven, so if the JS bundle fails to
     load it renders as a button with no label and no popover — and the reader
     is stranded on one connection with no way out. A native select still opens.

     The option's value is the connection's identity; the URL rides along in a
     data attribute. Both are ordinary escaped Blade output. --}}
@if ($connections !== [])
    <div class="tui-connection">
        <select class="tui-connection-select" aria-label="Connection" title="Connection"
                x-data
                x-on:change="const url = $event.target.selectedOptions[0]?.dataset.url; if (url) window.location = url;">
            @if ($current === '')
                {{-- The host didn't say which profile is live, so the control
                     must not claim one; a disabled placeholder holds the slot
                     instead of the first option quietly looking selected. --}}
                <option value="" selected disabled>Connection…</option>
            @endif
            @foreach ($connections as $connection)
                {{-- Written out rather than via @selected so the markup has no
                     stray space when the option is not the current one. --}}
                <option value="{{ $connection->value }}" data-url="{{ $connection->url }}"{{ $connection->value === $current ? ' selected' : '' }}>{{ $connection->label }}</option>
            @endforeach
        </select>
    </div>
@endif
