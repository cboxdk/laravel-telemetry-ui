@use('Cbox\TelemetryUi\Support\Format')

<x-telemetry-ui::card title="Failed browser requests" subtitle="fetch/XHR calls that failed for real users (5xx or network error). Click a row for the trace." span="2">
    @if ($error)
        <div class="tui-error">{{ $error }}</div>
    @elseif ($rows === [])
        <div class="tui-empty">No failed browser requests in this period. 🎉</div>
    @else
        <div class="tui-table-wrap">
            <table class="tui-table">
                <thead>
                    <tr>
                        <th>Request</th>
                        <th class="is-num">Status</th>
                        <th class="is-num">Count</th>
                        <th class="is-num">Last seen</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rows as $row)
                        <tr data-row-trace="{{ $row['traceId'] }}" title="Open a representative trace">
                            <td class="is-primary is-wide">{{ $row['url'] }}</td>
                            <td class="is-num tui-tone-danger">{{ $row['status'] }}</td>
                            <td class="is-num">{{ Format::count($row['count']) }}</td>
                            <td class="is-num tui-tone-dim">{{ $row['lastSeen'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</x-telemetry-ui::card>
