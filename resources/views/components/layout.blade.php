@props(['pages' => [], 'active' => null, 'services' => [], 'environments' => [], 'title' => 'Telemetry'])

<!DOCTYPE html>
<html lang="en" class="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }} — Telemetry</title>
    <link rel="stylesheet" href="{{ route('telemetry-ui.asset', ['asset' => 'telemetry-ui.css', 'v' => Cbox\TelemetryUi\Support\Assets::version('telemetry-ui.css')]) }}">
    <script src="{{ route('telemetry-ui.asset', ['asset' => 'telemetry-ui.js', 'v' => Cbox\TelemetryUi\Support\Assets::version('telemetry-ui.js')]) }}" defer></script>
    @livewireStyles
</head>
<body>
    <div class="tui-shell">
        <aside class="tui-sidebar">
            <x-telemetry-ui::scope-switcher :services="$services" :environments="$environments" />

            <nav class="tui-nav">
                @php($groups = collect($pages)->groupBy(fn ($meta) => $meta['group'] ?? '', preserveKeys: true))
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

    @livewireScripts
</body>
</html>
