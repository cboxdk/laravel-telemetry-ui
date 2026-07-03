<x-telemetry-ui::card title="Deploys" span="2">
    @if ($deploys === [])
        <div class="tui-empty">
            No deploys in this period. Emit markers from your pipeline with
            <code>php artisan telemetry:deploy --notes="…"</code>.
        </div>
    @else
        <div class="tui-table-wrap">
            <table class="tui-table">
                <thead>
                    <tr>
                        <th>When</th>
                        <th>Marker</th>
                        <th class="is-wide">Notes</th>
                        <th class="is-num">Trace</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($deploys as $deploy)
                        @php($at = (new DateTimeImmutable)->setTimestamp((int) ($deploy->timestampMs / 1000)))
                        <tr>
                            <td>{{ $at->format('d/m H:i:s') }}</td>
                            <td class="is-primary">
                                <span class="tui-annotation-dot" style="background: {{ $deploy->color }}"></span>
                                {{ $deploy->label }}
                            </td>
                            <td class="is-wide">{{ $deploy->notes ?? '—' }}</td>
                            <td class="is-num">
                                @if ($deploy->traceId)
                                    <a class="tui-trace-link" data-trace-id="{{ $deploy->traceId }}" href="{{ $this->traceUrl($deploy->traceId) }}">{{ substr($deploy->traceId, 0, 8) }}…</a>
                                @else
                                    —
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</x-telemetry-ui::card>
