<x-telemetry-ui::card :title="$title" :subtitle="$subtitle ?? null" :span="$span">
    @if ($error)
        <div class="tui-error">{{ $error }}</div>
    @else
        @if ($stats !== [])
            <x-telemetry-ui::stats :items="$stats" />
        @endif

        @if ($series === [])
            <div class="tui-empty">No data in this period.</div>
        @else
            <x-telemetry-ui::chart :series="$series" :type="$type" :unit="$unit" :height="$height" :annotations="$annotations" :min="$min" :max="$max" />
        @endif

        @if ($note)
            <div class="tui-note">{{ $note }}</div>
        @endif
    @endif
</x-telemetry-ui::card>
