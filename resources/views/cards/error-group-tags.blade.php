<x-telemetry-ui::card title="Tags" subtitle="What the occurrences have in common — one host, one release, one user?" span="2">
    @if ($error)
        <div class="tui-error">{{ $error }}</div>
    @elseif ($tags === [])
        <div class="tui-empty">No tag data on this group's occurrences.</div>
    @else
        <div class="tui-tagdist">
            @foreach ($tags as $tag)
                <div class="tui-tagdist-col">
                    <div class="tui-tagdist-head">{{ $tag['label'] }} <span class="tui-tone-dim">· {{ $tag['distinct'] }}</span></div>
                    @foreach ($tag['top'] as $value)
                        <div class="tui-tagdist-row" title="{{ $value['value'] }} — {{ $value['count'] }} occurrences ({{ $value['pct'] }}%)">
                            <span class="tui-tagdist-value">{{ $value['value'] }}</span>
                            <span class="tui-tagdist-bar"><span style="width: {{ $value['pct'] }}%"></span></span>
                            <span class="tui-tagdist-pct">{{ $value['pct'] }}%</span>
                        </div>
                    @endforeach
                </div>
            @endforeach
        </div>
    @endif
</x-telemetry-ui::card>
