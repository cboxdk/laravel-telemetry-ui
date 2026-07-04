<x-telemetry-ui::card :title="$route" subtitle="Route detail" span="2">
    <x-slot:actions>
        <a class="tui-btn" href="{{ $backUrl }}">← All requests</a>
    </x-slot:actions>

    @if ($error)
        <div class="tui-error">{{ $error }}</div>
    @else
        <x-telemetry-ui::stats :items="[
            ['label' => 'Requests', 'value' => $total, 'tone' => null],
            ['label' => 'Error rate', 'value' => $errorPct, 'tone' => $errorTone],
            ['label' => 'AVG', 'value' => $avg, 'tone' => 'dim'],
            ['label' => 'P95', 'value' => $p95, 'tone' => 'warn'],
        ]" />
    @endif
</x-telemetry-ui::card>
