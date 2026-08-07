@use('Cbox\TelemetryUi\Support\Format')

<x-telemetry-ui::card title="Warm-build latency" subtitle="How long Stache rebuilds take, by percentile — the distribution behind the P95." span="2">
    @if ($error)
        <div class="tui-error">{{ $error }}</div>
    @elseif ($rows === [])
        <div class="tui-empty">No Stache warms in this period.</div>
    @else
        <div class="tui-table-wrap">
            <table class="tui-table">
                <thead>
                    <tr>
                        <th>Percentile</th>
                        <th class="is-num">Warm build</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rows as $row)
                        <tr>
                            <td class="is-primary">{{ $row['percentile'] }}</td>
                            <td class="is-num">{{ $row['value'] === null ? '—' : Format::ms($row['value']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</x-telemetry-ui::card>
