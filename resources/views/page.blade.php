<x-telemetry-ui::layout :pages="$pages" :active="$page" :services="$services" :environments="$environments" :title="$pages[$page]['label']" :commands="$commands" :traceBase="$traceBase" :traceSentinel="$traceSentinel">
    <header class="tui-header">
        <h1>{{ $pages[$page]['label'] }}</h1>
        <div class="tui-header-right">
            <x-telemetry-ui::scope-switcher :services="$services" :environments="$environments" />
            <x-telemetry-ui::period-selector />
        </div>
    </header>

    <div class="tui-grid">
        @forelse ($cards as $card)
            {{-- lazy:'on-load' streams each card in its own request right after
                 paint (x-init, not x-intersect), so the shell + fast cards
                 render instantly and slow cards load in parallel. onPage is
                 passed explicitly because that later request no longer
                 carries the page route param. --}}
            @livewire($card, ['lazy' => 'on-load', 'onPage' => $page], key($card))
        @empty
            <div class="tui-empty">
                No cards registered for this page. Add cards in <code>config/telemetry-ui.php</code>
                or via <code>TelemetryUi::card(MyCard::class, page: '{{ $page }}')</code>.
            </div>
        @endforelse
    </div>
</x-telemetry-ui::layout>
