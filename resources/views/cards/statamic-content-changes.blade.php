@use('Cbox\TelemetryUi\Support\Format')

<x-telemetry-ui::card title="Content changes" span="2">
    @if ($error)
        <div class="tui-error">{{ $error }}</div>
    @elseif ($rows === [])
        <div class="tui-empty">No content changes in this period.</div>
    @else
        <div class="tui-table-wrap">
            <table class="tui-table">
                <thead>
                    <tr>
                        <th>Type</th>
                        <th>Action</th>
                        <th class="is-num">Count</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rows as $row)
                        <tr>
                            <td class="is-primary">{{ $row['type'] }}</td>
                            <td><span class="tui-badge">{{ $row['action'] }}</span></td>
                            <td class="is-num">{{ Format::count($row['count']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</x-telemetry-ui::card>
