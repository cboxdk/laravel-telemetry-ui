<x-telemetry-ui::card :title="$title" :subtitle="$subtitle ?? null" span="2">
    <x-slot:actions>
        <a class="tui-btn" href="{{ $backUrl }}">{{ $backLabel ?? '← Back' }}</a>
    </x-slot:actions>

    @if ($error)
        <div class="tui-error">{{ $error }}</div>
    @else
        <x-telemetry-ui::stats :items="$stats" />
    @endif
</x-telemetry-ui::card>
