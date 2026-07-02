@props(['title' => null, 'span' => 1])

<section {{ $attributes->merge(['class' => 'tui-card tui-span-'.$span]) }}>
    @if ($title)
        <header class="tui-card-header">
            <h2>{{ $title }}</h2>
            @isset($actions)
                <div class="tui-card-actions">{{ $actions }}</div>
            @endisset
        </header>
    @endif

    <div class="tui-card-body">
        {{ $slot }}
    </div>
</section>
