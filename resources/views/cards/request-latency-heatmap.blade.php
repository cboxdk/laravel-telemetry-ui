<x-telemetry-ui::card title="Latency distribution" subtitle="Where requests actually land over time — the spread the p95 line hides" span="2">
    @if ($error)
        <div class="tui-error">{{ $error }}</div>
    @elseif ($heatmap === null)
        <div class="tui-empty">No request-latency histogram in this period.</div>
    @else
        <div wire:ignore wire:key="heatmap-{{ md5(json_encode($heatmap)) }}"
             x-data="telemetryUiHeatmap(@js($heatmap))" class="tui-heatmap-wrap">
            <div class="tui-heatmap" style="height: 210px"></div>
        </div>
    @endif
</x-telemetry-ui::card>
