<x-telemetry-ui::card title="Requests / min" span="2">
    @if ($error)
        <div class="tui-error">{{ $error }}</div>
    @elseif ($series === [])
        <div class="tui-empty">No request metrics in this period.</div>
    @else
        <x-telemetry-ui::chart :series="$series" unit="req/min" />
    @endif
</x-telemetry-ui::card>
