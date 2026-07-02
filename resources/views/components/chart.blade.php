@props(['series' => [], 'type' => 'line', 'height' => 240, 'unit' => null])

{{--
    wire:ignore keeps Livewire's morph away from the ECharts-managed DOM;
    the series-derived wire:key swaps the node wholesale when data changes,
    which re-runs Alpine init with the fresh series.
--}}
<div
    wire:ignore
    wire:key="chart-{{ md5(json_encode($series)) }}"
    x-data="telemetryUiChart(@js($series), @js($type), @js($unit))"
    class="tui-chart"
    style="height: {{ (int) $height }}px"
></div>
