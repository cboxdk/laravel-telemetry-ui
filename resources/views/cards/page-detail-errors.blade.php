@use('Cbox\TelemetryUi\Support\Format')

<x-telemetry-ui::card title="Errors" subtitle="Browser errors seen on this page, grouped by fingerprint. Click a row for the issue's stacktrace and root cause." span="2">
    @if ($error)
        <div class="tui-error">{{ $error }}</div>
    @elseif ($rows === [])
        <div class="tui-empty">No errors on this page in this period. 🎉</div>
    @else
        @if ($sampled)
            <div class="tui-note">Sampled — counts are lower bounds.</div>
        @endif

        <div class="tui-table-wrap">
            <table class="tui-table">
                <thead>
                    <tr>
                        <th>Error</th>
                        <th>Trend</th>
                        <th class="is-num">Events</th>
                        <th class="is-num">Last seen</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rows as $row)
                        <tr data-row-href="{{ $this->showUrl($row['group']) }}" title="Open this issue's page — trend, tags, stacktrace, root cause">
                            <td class="is-primary is-wide">
                                <span class="tui-err-type">{{ $row['type'] !== '' ? $row['type'] : $row['group'] }}</span>
                                @if ($row['message'] !== '')
                                    <span class="tui-err-msg">{{ Str::limit($row['message'], 120) }}</span>
                                @endif
                            </td>
                            <td><x-telemetry-ui::sparkline :points="$row['buckets']" color="#f87171" /></td>
                            <td class="is-num tui-tone-danger">{{ Format::count($row['count']) }}</td>
                            <td class="is-num tui-tone-dim">{{ $row['lastSeen'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</x-telemetry-ui::card>
