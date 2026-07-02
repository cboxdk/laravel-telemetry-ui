@use('Cbox\TelemetryUi\Support\Format')

<x-telemetry-ui::card title="Upstream hosts" span="2">
    @if ($error)
        <div class="tui-error">{{ $error }}</div>
    @elseif ($rows === [])
        <div class="tui-empty">No outgoing requests in this period.</div>
    @else
        <div class="tui-table-wrap">
            <table class="tui-table">
                <thead>
                    <tr>
                        <th>Host</th>
                        <th class="is-num">1/2/3XX</th>
                        <th class="is-num">4XX</th>
                        <th class="is-num">5XX</th>
                        <th class="is-num">Conn. failures</th>
                        <th class="is-num">Total</th>
                        <th class="is-num">AVG</th>
                        <th class="is-num">P95</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rows as $row)
                        <tr>
                            <td class="is-primary">{{ $row['host'] }}</td>
                            <td class="is-num">{{ Format::count($row['ok']) }}</td>
                            <td class="is-num {{ $row['4xx'] > 0 ? 'tui-tone-warn' : '' }}">{{ Format::count($row['4xx']) }}</td>
                            <td class="is-num {{ $row['5xx'] > 0 ? 'tui-tone-danger' : '' }}">{{ Format::count($row['5xx']) }}</td>
                            <td class="is-num {{ $row['failures'] > 0 ? 'tui-tone-danger' : '' }}">{{ Format::count($row['failures']) }}</td>
                            <td class="is-num is-primary">{{ Format::count($row['total']) }}</td>
                            <td class="is-num">{{ $row['total'] > 0 ? Format::ms($row['time'] / $row['total']) : '—' }}</td>
                            <td class="is-num">{{ $row['p95'] !== null ? Format::ms($row['p95']) : '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</x-telemetry-ui::card>
