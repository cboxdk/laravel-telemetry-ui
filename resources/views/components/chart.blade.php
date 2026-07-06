@props(['series' => [], 'type' => 'line', 'height' => 240, 'unit' => null, 'annotations' => [], 'min' => null, 'max' => null])

{{--
    wire:ignore keeps Livewire's morph away from the ECharts-managed DOM;
    the series-derived wire:key swaps the node wholesale when data changes,
    which re-runs Alpine init with the fresh series.
--}}
<div
    wire:ignore
    wire:key="chart-{{ md5(json_encode([$series, $annotations, $min, $max])) }}"
    x-data="telemetryUiChart(@js($series), @js($type), @js($unit), @js($annotations), @js(['min' => $min, 'max' => $max]))"
    class="tui-chart-wrap"
>
    <div class="tui-chart" style="height: {{ (int) $height }}px"></div>

    {{-- Click-callout for an annotation marker: the full detail the thin
         line can't carry — label, exact time, notes, and its trace. --}}
    <div class="tui-annotation-pop" x-cloak x-show="marker"
         x-on:click.outside="marker = null" x-on:keydown.escape.window="marker = null"
         :style="popStyle()">
        <template x-if="marker">
            <div>
                <div class="tui-annotation-pop-head">
                    <span class="tui-annotation-dot" :style="'background:' + marker.color"></span>
                    <strong x-text="marker.label"></strong>
                    <button type="button" class="tui-annotation-pop-close" x-on:click="marker = null" title="Close">✕</button>
                </div>
                <div class="tui-annotation-pop-time" x-text="marker.time"></div>
                <div class="tui-annotation-pop-notes" x-show="marker.notes" x-text="marker.notes"></div>
                <div class="tui-annotation-pop-actions" x-show="marker.traceId">
                    <button type="button" class="tui-btn tui-btn-sm"
                            x-on:click="window.telemetryUiOpenDrawer?.('Trace'); window.Livewire?.dispatch('telemetry-ui:open-trace', { traceId: marker.traceId }); marker = null">
                        ⇄ Open trace
                    </button>
                </div>
            </div>
        </template>
    </div>
</div>
