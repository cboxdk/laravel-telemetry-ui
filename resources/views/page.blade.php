<x-telemetry-ui::layout :pages="$pages" :active="$page" :services="$services" :environments="$environments" :title="$pages[$page]['label']" :commands="$commands" :traceBase="$traceBase" :traceSentinel="$traceSentinel">
    <header class="tui-header">
        <h1>{{ $pages[$page]['label'] }}</h1>
        <x-telemetry-ui::period-selector />
    </header>

    <div class="tui-grid">
        @forelse ($cards as $card)
            @livewire($card, ['lazy' => true])
        @empty
            <div class="tui-empty">
                No cards registered for this page. Add cards in <code>config/telemetry-ui.php</code>
                or via <code>TelemetryUi::card(MyCard::class, page: '{{ $page }}')</code>.
            </div>
        @endforelse
    </div>
</x-telemetry-ui::layout>
