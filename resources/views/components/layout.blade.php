@props(['pages' => [], 'active' => null, 'services' => [], 'environments' => [], 'title' => 'Telemetry', 'commands' => [], 'traceBase' => '', 'traceSentinel' => ''])

@php
    use Illuminate\Support\Str;

    // Areas = the nav "tier 1". Ungrouped pages fold into one "Overview" area;
    // every group becomes its own area. Each area's pages are "tier 2" (subnav).
    $visible = collect($pages)->reject(fn ($meta) => $meta['hidden'] ?? false);
    $grouped = $visible->groupBy(fn ($meta) => $meta['group'] ?? '', preserveKeys: true);
    $activeGroup = $pages[$active]['group'] ?? '';

    // Lucide outline paths per area (matched by lowercased group name), 24-box.
    $areaIcon = function (string $group): string {
        return match (Str::lower($group)) {
            '', 'overview' => '<path d="M3 12 12 3l9 9"/><path d="M5 10v10h14V10"/>',
            'activity' => '<path d="M22 12h-4l-3 9L9 3l-3 9H2"/>',
            'monitoring' => '<path d="M3 3v18h18"/><path d="m19 9-5 5-4-4-3 3"/>',
            'statamic' => '<path d="m12 2 9 5-9 5-9-5 9-5Z"/><path d="m3 12 9 5 9-5"/><path d="m3 17 9 5 9-5"/>',
            'security' => '<path d="M20 13c0 5-3.5 7.5-8 9-4.5-1.5-8-4-8-9V6l8-3 8 3z"/>',
            default => '<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/>',
        };
    };

    $areas = collect($grouped)->map(function ($items, $group) {
        return [
            'key' => $group === '' ? '__overview' : $group,
            'label' => $group === '' ? 'Overview' : $group,
            'group' => $group,
            'first' => $items->keys()->first(),
            'pages' => $items,
        ];
    })->values();
    $activeAreaKey = $activeGroup === '' ? '__overview' : $activeGroup;
    $activeArea = $areas->firstWhere('key', $activeAreaKey) ?? $areas->first();

    $pageUrl = fn (string $slug) => route('telemetry-ui.page', array_filter([
        'page' => $slug === 'dashboard' ? null : $slug,
        'period' => request('period'), 'service' => request('service'), 'env' => request('env'),
    ]));
    $brand = config('telemetry-ui.brand.name') ?: config('app.name');
@endphp

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>{{ $title }} — {{ config('telemetry-ui.brand.name') ?: 'Telemetry' }}</title>
    {{-- Respect the saved theme before paint to avoid a flash. --}}
    <script>try{var t=localStorage.getItem('tui-theme');if(t==='dark'||(!t&&matchMedia('(prefers-color-scheme: dark)').matches))document.documentElement.classList.add('dark');}catch(e){}</script>
    <link rel="stylesheet" href="{{ route('telemetry-ui.asset', ['asset' => 'telemetry-ui.css', 'v' => Cbox\TelemetryUi\Support\Assets::version('telemetry-ui.css')]) }}">
    <script src="{{ route('telemetry-ui.asset', ['asset' => 'telemetry-ui.js', 'v' => Cbox\TelemetryUi\Support\Assets::version('telemetry-ui.js')]) }}" defer></script>
    @if ($accent = config('telemetry-ui.brand.accent'))
        <style>:root{ --tui-accent: {{ preg_replace('/[^a-zA-Z0-9#(),.%\s-]/', '', (string) $accent) }} }</style>
    @endif
    @livewireStyles
</head>
<body>
    <div class="tui-shell" x-data="{
            nav: false,
            pinned: JSON.parse(localStorage.getItem('tui-rail-pinned') || 'false'),
            hover: false,
            sub: JSON.parse(localStorage.getItem('tui-sub-collapsed') || 'false'),
            togglePin() { this.pinned = ! this.pinned; localStorage.setItem('tui-rail-pinned', JSON.stringify(this.pinned)); },
            toggleSub() { this.sub = ! this.sub; localStorage.setItem('tui-sub-collapsed', JSON.stringify(this.sub)); },
         }"
         x-on:keydown.window.escape="nav = false"
         x-on:keydown.window.period.cmd.prevent="toggleSub()"
         x-on:keydown.window.period.ctrl.prevent="toggleSub()">
        {{-- Mobile topbar: brand + hamburger opens the rail+subnav as a drawer. --}}
        <header class="tui-topbar">
            <button type="button" class="tui-topbar-menu" x-on:click="nav = true"
                    aria-label="Open navigation" aria-controls="tui-nav" x-bind:aria-expanded="nav">
                <span></span><span></span><span></span>
            </button>
            <span class="tui-topbar-brand">{{ $brand }}</span>
        </header>

        <div class="tui-sidebar-overlay" x-show="nav" x-cloak x-on:click="nav = false" x-transition.opacity></div>

        <div class="tui-navwrap" id="tui-nav" x-bind:class="{ 'is-open': nav, 'rail-pinned': pinned }">
            {{-- TIER 1 — icon rail. 3 states (Intercom): minimised / hover-overlay / pinned.
                 Hover expands it (unpinned only) as a floating card with labels + pin. --}}
            <aside class="tui-rail" x-bind:class="{ open: pinned || hover, unpinned: ! pinned }"
                   x-on:mouseenter="hover = true" x-on:mouseleave="hover = false">
                <div class="tui-rail-hd">
                    <div class="tui-rail-brand" title="{{ $brand }}">
                        @if ($logo = config('telemetry-ui.brand.logo'))
                            <img src="{{ $logo }}" alt="{{ $brand }}">
                        @else
                            <span>{{ Str::upper(Str::substr($brand, 0, 1)) }}</span>
                        @endif
                    </div>
                    <button type="button" class="tui-pin" x-bind:class="{ set: pinned }" x-on:click="togglePin()"
                            x-bind:title="pinned ? 'Unpin sidebar' : 'Pin sidebar'" aria-label="Pin sidebar">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M12 17v5"/><path d="M9 10.8V6a3 3 0 1 1 6 0v4.8l2.7 2.7a1 1 0 0 1-.7 1.7H7a1 1 0 0 1-.7-1.7Z"/></svg>
                    </button>
                </div>
                <nav class="tui-rail-nav">
                    @foreach ($areas as $area)
                        <a href="{{ $pageUrl($area['first']) }}" title="{{ $area['label'] }}"
                           class="tui-rail-item {{ $area['key'] === $activeAreaKey ? 'is-active' : '' }}">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">{!! $areaIcon($area['group']) !!}</svg>
                            <span class="lbl">{{ $area['label'] }}</span>
                        </a>
                    @endforeach
                </nav>
                <div class="tui-rail-foot">
                    <button type="button" class="tui-rail-item tui-theme-toggle" title="Toggle theme"
                            x-on:click="const d=document.documentElement.classList.toggle('dark');localStorage.setItem('tui-theme',d?'dark':'light')">
                        <svg class="tui-icon-sun" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/></svg>
                        <svg class="tui-icon-moon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3a6 6 0 0 0 9 9 9 9 0 1 1-9-9Z"/></svg>
                        <span class="lbl">Theme</span>
                    </button>
                    <span class="tui-rail-item" title="{{ $brand }}" style="cursor:default">
                        <span class="tui-rail-avatar">{{ Str::upper(Str::substr($brand, 0, 2)) }}</span>
                        <span class="lbl">{{ $brand }}</span>
                    </span>
                </div>
            </aside>
            {{-- Floating overlay needs a spacer to hold the rail's 56px slot in flow. --}}
            <div class="tui-rail-spacer" x-show="! pinned" x-cloak></div>

            {{-- TIER 2 — contextual subnav; collapses to a vertical-label strip (⌘.). --}}
            @if ($activeArea)
                <aside class="tui-subnav" x-bind:class="{ collapsed: sub }">
                    <div class="tui-subnav-hd">
                        <span>{{ $activeArea['label'] }}</span>
                        <button type="button" class="tui-subnav-toggle" x-on:click="toggleSub()" title="Collapse panel (⌘.)" aria-label="Collapse panel">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M9 3v18"/></svg>
                        </button>
                    </div>
                    <button type="button" class="tui-subnav-search"
                            x-on:click="window.dispatchEvent(new KeyboardEvent('keydown',{key:'k',metaKey:true}))">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/></svg>
                        <span>Search</span><kbd>⌘K</kbd>
                    </button>
                    <nav class="tui-subnav-nav">
                        @foreach ($activeArea['pages'] as $slug => $meta)
                            <a href="{{ $pageUrl($slug) }}" class="tui-subnav-item {{ $slug === $active ? 'is-active' : '' }}">
                                <span>{{ $meta['label'] }}</span>
                                @isset($meta['count'])<span class="cnt">{{ $meta['count'] }}</span>@endisset
                            </a>
                        @endforeach
                    </nav>
                    {{-- Collapsed strip: whole thing is the expand target. --}}
                    <div class="tui-strip" x-on:click="toggleSub()">
                        <span class="vlabel">{{ $activeArea['label'] }}</span>
                        <button type="button" x-on:click.stop="toggleSub()" title="Expand (⌘.)" aria-label="Expand panel">»</button>
                    </div>
                </aside>
            @endif
        </div>

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
