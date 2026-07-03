@props(['title' => null, 'subtitle' => null, 'span' => 1])

<section {{ $attributes->merge(['class' => 'tui-card tui-span-'.$span]) }}>
    @if ($title)
        <header class="tui-card-header">
            <div class="tui-card-heading">
                <h2>{{ $title }}</h2>
                @if ($subtitle)
                    <p class="tui-card-subtitle">{{ $subtitle }}</p>
                @endif
            </div>
            @isset($actions)
                <div class="tui-card-actions">{{ $actions }}</div>
            @endisset
        </header>
    @endif

    <div class="tui-card-body">
        {{ $slot }}
    </div>
</section>
