@props(['items' => []])

{{-- items: ['label' => ..., 'value' => ..., 'tone' => null|'ok'|'warn'|'danger'|'dim'].
     Optional, for a Datadog-style KPI tile (all backward-compatible — omit and the
     tile renders as a plain label + value):
       'delta'      => '▲ 12%'   pre-formatted period-over-period change
       'deltaTone'  => tone       colour for the delta (ok = good direction, danger = bad)
       'baseline'   => 'typ 30%'  the usual value for this scope
       'points'     => list<float> inline sparkline of the window
       'sparkColor' => css colour for the sparkline (defaults to muted) --}}
<div class="tui-stats">
    @foreach ($items as $item)
        <div @class(['tui-stat', 'has-trend' => ! empty($item['points']) || ! empty($item['delta']) || ! empty($item['baseline'])])>
            <span class="tui-stat-label">{{ $item['label'] }}</span>
            <span class="tui-stat-value tui-tone-{{ $item['tone'] ?? 'default' }}">{{ $item['value'] }}</span>
            @if (! empty($item['delta']) || ! empty($item['baseline']))
                <span class="tui-stat-meta">
                    @if (! empty($item['delta']))
                        <span class="tui-stat-delta tui-tone-{{ $item['deltaTone'] ?? 'dim' }}">{{ $item['delta'] }}</span>
                    @endif
                    @if (! empty($item['baseline']))
                        <span class="tui-stat-base">{{ $item['baseline'] }}</span>
                    @endif
                </span>
            @endif
            @if (! empty($item['points']))
                <x-telemetry-ui::sparkline :points="$item['points']" :color="$item['sparkColor'] ?? 'var(--muted-foreground)'" />
            @endif
        </div>
    @endforeach
</div>
