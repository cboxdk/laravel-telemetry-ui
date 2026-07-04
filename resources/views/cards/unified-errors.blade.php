@use('Cbox\TelemetryUi\Support\Format')

<x-telemetry-ui::card title="Errors" subtitle="Every exception — frontend and backend — grouped by fingerprint. Click a row for a representative trace." span="2">
    @if ($error)
        <div class="tui-error">{{ $error }}</div>
    @elseif ($rows === [])
        <div class="tui-empty">No errors in this period. 🎉</div>
    @else
        @if ($sampled)
            <div class="tui-note">Showing a recent sample — counts are for the traces scanned, not the full retention total.</div>
        @endif
        <div class="tui-table-wrap">
            <table class="tui-table">
                <thead>
                    <tr>
                        <th>Source</th>
                        <th>Error</th>
                        <th class="is-num">Count</th>
                        <th class="is-num">Last seen</th>
                        <th class="is-num">Traces</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rows as $row)
                        <tr data-row-trace="{{ $row['traceId'] }}" title="Open a representative trace">
                            <td>
                                @if ($row['source'] === 'frontend')
                                    <span class="tui-badge tui-badge-web" title="Browser (RUM) error">web</span>
                                @elseif ($row['source'] === 'full-stack')
                                    <span class="tui-badge tui-badge-web" title="Seen in both browser and backend">full-stack</span>
                                @else
                                    <span class="tui-badge tui-badge-info" title="Backend error">server</span>
                                @endif
                            </td>
                            <td class="is-primary is-wide">
                                <span class="tui-err-type">{{ $row['type'] !== '' ? $row['type'] : $row['group'] }}</span>
                                @if ($row['message'] !== '')
                                    <span class="tui-err-msg">{{ Str::limit($row['message'], 120) }}</span>
                                @endif
                            </td>
                            <td class="is-num tui-tone-danger">{{ Format::count($row['count']) }}</td>
                            <td class="is-num tui-tone-dim">{{ $row['lastSeen'] }}</td>
                            <td class="is-num"><a href="{{ $row['tracesUrl'] }}" x-on:click.stop title="All traces for this error">⧉ all</a></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</x-telemetry-ui::card>
