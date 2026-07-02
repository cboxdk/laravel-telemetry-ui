<x-telemetry-ui::card title="Static cache operations / min" span="2">
    @if ($error)
        <div class="tui-error">{{ $error }}</div>
    @elseif ($series === [])
        <div class="tui-empty">No static-cache activity in this period.</div>
    @else
        <x-telemetry-ui::chart :series="$series" type="area" unit="ops/min" />
    @endif
</x-telemetry-ui::card>
