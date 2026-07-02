@use('Cbox\TelemetryUi\Support\Format')

<x-telemetry-ui::card :title="$title" span="2">
    @if ($error)
        <div class="tui-error">{{ $error }}</div>
    @elseif ($rows === [])
        <div class="tui-empty">No data in this period.</div>
    @else
        <div class="tui-table-wrap">
            <table class="tui-table">
                <thead>
                    <tr>
                        <th>{{ $keyColumn }}</th>
                        @foreach ($outcomeColumns as $outcome)
                            <th class="is-num">{{ ucfirst($outcome) }}</th>
                        @endforeach
                        <th class="is-num">AVG</th>
                        <th class="is-num">P95</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rows as $row)
                        <tr>
                            <td class="is-primary is-wide">{{ $row['name'] }}</td>
                            @foreach ($outcomeColumns as $outcome)
                                <td class="is-num {{ $outcome === 'failed' && $row['outcomes'][$outcome] > 0 ? 'tui-tone-danger' : '' }}">
                                    {{ Format::count($row['outcomes'][$outcome]) }}
                                </td>
                            @endforeach
                            <td class="is-num">{{ $row['count'] > 0 ? Format::ms($row['time'] / $row['count']) : '—' }}</td>
                            <td class="is-num">{{ $row['p95'] !== null ? Format::ms($row['p95']) : '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</x-telemetry-ui::card>
