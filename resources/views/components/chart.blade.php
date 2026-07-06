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
    :class="{ 'tui-marker-active': marker }"
>
    <div class="tui-chart" style="height: {{ (int) $height }}px"></div>

    {{-- The annotation callout, ANCHORED to the marker line: hovering the line
         opens it and the pointer can move into it to reach its actions; leaving
         both closes it. Clustered rollouts show the count, the span
         (first → last) and the covered hosts. --}}
    <div class="tui-annotation-pop" x-cloak x-show="marker" :data-side="popSide()"
         x-on:mouseenter="cancelHide()" x-on:mouseleave="scheduleHide()"
         x-on:keydown.escape.window="closeMarker()"
         :style="popStyle()">
        <template x-if="marker">
            <div>
                <div class="tui-annotation-pop-head">
                    <span class="tui-annotation-dot" :style="'background:' + marker.color"></span>
                    <strong x-text="marker.label + (marker.count > 1 ? ' ×' + marker.count : '')"></strong>
                </div>
                <div class="tui-annotation-pop-time"
                     x-text="marker.timeEnd ? marker.time + ' → ' + marker.timeEnd : marker.time"></div>
                <div class="tui-annotation-pop-notes" x-show="marker.notes" x-text="marker.notes"></div>
                <div class="tui-annotation-pop-hosts" x-show="marker.hostCount > 0"
                     x-text="marker.hostCount + (marker.hostCount === 1 ? ' host: ' : ' hosts: ')
                        + (marker.hosts || []).join(', ')
                        + (marker.hostCount > (marker.hosts || []).length ? ' +' + (marker.hostCount - marker.hosts.length) + ' more' : '')"></div>
                <div class="tui-annotation-pop-actions" x-show="marker.traceId">
                    <button type="button" class="tui-btn tui-btn-sm"
                            x-on:click="window.telemetryUiOpenDrawer?.('Trace'); window.Livewire?.dispatch('telemetry-ui:open-trace', { traceId: marker.traceId }); closeMarker()">
                        ⇄ Open trace
                    </button>
                </div>
            </div>
        </template>
    </div>
</div>
