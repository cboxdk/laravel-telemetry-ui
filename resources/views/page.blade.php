<!DOCTYPE html>
<html lang="en" class="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $pages[$page]['label'] }} — Telemetry</title>
    <link rel="stylesheet" href="{{ route('telemetry-ui.asset', ['asset' => 'telemetry-ui.css']) }}">
    <script src="{{ route('telemetry-ui.asset', ['asset' => 'telemetry-ui.js']) }}" defer></script>
</head>
<body>
    <div class="tui-shell">
        <aside class="tui-sidebar">
            <div class="tui-brand">
                <span class="tui-brand-name">{{ config('app.name') }}</span>
                <span class="tui-brand-env">{{ config('app.env') }}</span>
            </div>

            <nav class="tui-nav">
                @php($groups = collect($pages)->groupBy(fn ($meta) => $meta['group'] ?? '', preserveKeys: true))
                @foreach ($groups as $group => $items)
                    @if ($group !== '')
                        <div class="tui-nav-group">{{ $group }}</div>
                    @endif
                    @foreach ($items as $slug => $meta)
                        <a href="{{ route('telemetry-ui.page', ['page' => $slug === 'dashboard' ? null : $slug, 'period' => request('period')]) }}"
                           class="tui-nav-item {{ $slug === $page ? 'is-active' : '' }}">
                            {{ $meta['label'] }}
                        </a>
                    @endforeach
                @endforeach
            </nav>
        </aside>

        <main class="tui-main">
            <header class="tui-header">
                <h1>{{ $pages[$page]['label'] }}</h1>
                <x-telemetry-ui::period-selector />
            </header>

            <div class="tui-grid">
                @forelse ($cards as $card)
                    @livewire($card)
                @empty
                    <div class="tui-empty">
                        No cards registered for this page. Add cards in <code>config/telemetry-ui.php</code>
                        or via <code>TelemetryUi::card(MyCard::class, page: '{{ $page }}')</code>.
                    </div>
                @endforelse
            </div>
        </main>
    </div>
</body>
</html>
