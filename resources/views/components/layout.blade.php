@props(['pages' => [], 'active' => null, 'services' => [], 'environments' => [], 'title' => 'Telemetry', 'commands' => [], 'traceBase' => '', 'traceSentinel' => ''])

<!DOCTYPE html>
<html lang="en" class="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }} — {{ config('telemetry-ui.brand.name') ?: 'Telemetry' }}</title>
    <link rel="stylesheet" href="{{ route('telemetry-ui.asset', ['asset' => 'telemetry-ui.css', 'v' => Cbox\TelemetryUi\Support\Assets::version('telemetry-ui.css')]) }}">
    <script src="{{ route('telemetry-ui.asset', ['asset' => 'telemetry-ui.js', 'v' => Cbox\TelemetryUi\Support\Assets::version('telemetry-ui.js')]) }}" defer></script>
    @if ($accent = config('telemetry-ui.brand.accent'))
        {{-- Colour chars only (hex / rgb() / hsl() / named). No slash, so an
             external url(...) can't be smuggled into the custom property. --}}
        <style>:root{ --tui-accent: {{ preg_replace('/[^a-zA-Z0-9#(),.%\s-]/', '', (string) $accent) }} }</style>
    @endif
    @livewireStyles
</head>
<body>
    <div class="tui-shell">
        <aside class="tui-sidebar">
            <x-telemetry-ui::scope-switcher :services="$services" :environments="$environments" />

            <nav class="tui-nav">
                @php($groups = collect($pages)->reject(fn ($meta) => $meta['hidden'] ?? false)->groupBy(fn ($meta) => $meta['group'] ?? '', preserveKeys: true))
                @foreach ($groups as $group => $items)
                    @if ($group !== '')
                        <div class="tui-nav-group">{{ $group }}</div>
                    @endif
                    @foreach ($items as $slug => $meta)
                        <a href="{{ route('telemetry-ui.page', array_filter(['page' => $slug === 'dashboard' ? null : $slug, 'period' => request('period'), 'service' => request('service'), 'env' => request('env')])) }}"
                           class="tui-nav-item {{ $slug === $active ? 'is-active' : '' }}">
                            {{ $meta['label'] }}
                        </a>
                    @endforeach
                @endforeach
            </nav>
        </aside>

        <main class="tui-main">
            {{ $slot }}
        </main>
    </div>

    @livewire('telemetry-ui.trace-drawer')

    <div class="tui-palette-root" x-data="telemetryUiPalette(@js($commands), @js($traceBase), @js($traceSentinel))" x-cloak
         x-on:keydown.window.cmd.k.prevent="open()" x-on:keydown.window.ctrl.k.prevent="open()"
         x-on:keydown.window.slash="maybeOpenOnSlash($event)">
        <div class="tui-palette-overlay" x-show="isOpen" x-on:click="close()" x-transition.opacity></div>
        <div class="tui-palette" x-show="isOpen" x-transition>
            <input type="text" class="tui-palette-input" placeholder="Jump to a page, service, environment — or paste a trace id…"
                   x-model="query" x-ref="input"
                   x-on:keydown.down.prevent="move(1)" x-on:keydown.up.prevent="move(-1)"
                   x-on:keydown.enter.prevent="go()" x-on:keydown.escape="close()">
            <div class="tui-palette-list">
                <template x-for="(item, i) in results" :key="item.type + item.label + i">
                    <a class="tui-palette-item" :class="{ 'is-active': i === cursor }" :href="item.href"
                       x-on:mouseenter="cursor = i">
                        <span class="tui-palette-kind" x-text="item.type"></span>
                        <span class="tui-palette-label" x-text="item.label"></span>
                        <span class="tui-palette-group" x-text="item.group"></span>
                    </a>
                </template>
                <div class="tui-palette-empty" x-show="results.length === 0" x-text="'No matches'"></div>
            </div>
            <div class="tui-palette-hint">↑↓ navigate · ↵ open · esc close</div>
        </div>
    </div>

    @livewireScripts
</body>
</html>
