@use('Cbox\TelemetryUi\Support\Format')

<x-telemetry-ui::card title="Paths" subtitle="Concrete URLs behind this route pattern — click a row to tail that path in the request log" span="2">
    @if ($error)
        <div class="tui-error">{{ $error }}</div>
    @elseif ($rows === [])
        <div class="tui-empty">No sampled requests for this route in the period.</div>
    @else
        <div class="tui-table-wrap">
            <table class="tui-table">
                <thead>
                    <tr>
                        <th>Path</th>
                        <th class="is-num">Requests</th>
                        <th class="is-num">Avg</th>
                        <th class="is-num">Max</th>
                        <th class="is-num">4xx/5xx</th>
                        <th class="is-num">Latest</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rows as $row)
                        <tr data-row-href="{{ $this->logUrl($row['path']) }}" title="Tail this path in the request log">
                            <td class="is-primary is-wide">{{ Str::limit($row['path'], 90) }}</td>
                            <td class="is-num">{{ Format::count($row['count']) }}</td>
                            <td class="is-num tui-tone-dim">{{ Format::ms($row['sumMs'] / max(1, $row['count'])) }}</td>
                            <td class="is-num {{ $row['maxMs'] > 1000 ? 'tui-tone-warn' : 'tui-tone-dim' }}">{{ Format::ms($row['maxMs']) }}</td>
                            <td class="is-num {{ $row['errors'] > 0 ? 'tui-tone-danger' : 'tui-tone-dim' }}">{{ $row['errors'] }}</td>
                            <td class="is-num">
                                @if ($row['traceId'] !== '')
                                    <a class="tui-trace-link" data-trace-id="{{ $row['traceId'] }}" href="{{ $this->traceUrl($row['traceId']) }}" title="Open the newest request's story">⇄</a>
                                @else
                                    —
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="tui-note">Aggregated from a bounded trace sample. Row → request log for the path; ⇄ → the newest request's full story.</div>
    @endif
</x-telemetry-ui::card>
