@use('Cbox\TelemetryUi\Support\Format')

<x-telemetry-ui::card title="Duplicate queries (N+1)" subtitle="Queries that repeated identically within one trace — the classic N+1 smell, named" span="2">
    @if ($error)
        <div class="tui-error">{{ $error }}</div>
    @elseif ($rows === [])
        <div class="tui-empty">No duplicate-query detections in this period. 🎉</div>
    @else
        <div class="tui-table-wrap">
            <table class="tui-table">
                <thead>
                    <tr>
                        <th>Query</th>
                        <th>Connection</th>
                        <th class="is-num">Traces affected</th>
                        <th class="is-num">Worst repeat</th>
                        <th class="is-num">Trace</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rows as $row)
                        <tr @if ($row['traceId'] !== '') data-row-trace="{{ $row['traceId'] }}" @endif>
                            <td class="is-primary is-wide">{{ Str::limit($row['query'], 160) }}</td>
                            <td>{{ $row['connection'] !== '' ? $row['connection'] : '—' }}</td>
                            <td class="is-num tui-tone-warn">{{ Format::count($row['traces']) }}</td>
                            <td class="is-num tui-tone-danger">×{{ $row['worstRepeat'] }}</td>
                            <td class="is-num">
                                @if ($row['traceId'] !== '')
                                    <a class="tui-trace-link" data-trace-id="{{ $row['traceId'] }}" href="{{ $this->traceUrl($row['traceId']) }}">{{ substr($row['traceId'], 0, 8) }}…</a>
                                @else
                                    —
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="tui-note">Fired once per distinct query when it crosses the repeat threshold (default 3). Fix with eager loading or caching.</div>
    @endif
</x-telemetry-ui::card>
