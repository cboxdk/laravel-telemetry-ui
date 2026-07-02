@use('Cbox\TelemetryUi\Support\Format')

<x-telemetry-ui::card title="Service graph" span="2">
    @if ($error)
        <div class="tui-error">{{ $error }}</div>
    @elseif ($edges === [])
        <div class="tui-empty">
            No service-graph edges in this period. Requires Tempo's metrics-generator
            (service-graphs processor) remote-writing to your metrics backend.
        </div>
    @else
        <div class="tui-table-wrap">
            <table class="tui-table">
                <thead>
                    <tr>
                        <th>Client</th>
                        <th></th>
                        <th>Server</th>
                        <th class="is-num">Requests</th>
                        <th class="is-num">Failed</th>
                        <th class="is-num">P95</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($edges as $edge)
                        <tr>
                            <td><span class="tui-badge" style="border-color: {{ $this->color($edge['client']) }}55; color: {{ $this->color($edge['client']) }}">{{ $edge['client'] }}</span></td>
                            <td class="tui-chain-arrow">→</td>
                            <td><span class="tui-badge" style="border-color: {{ $this->color($edge['server']) }}55; color: {{ $this->color($edge['server']) }}">{{ $edge['server'] }}</span></td>
                            <td class="is-num is-primary">{{ Format::count($edge['requests']) }}</td>
                            <td class="is-num {{ $edge['failed'] > 0 ? 'tui-tone-danger' : '' }}">{{ Format::count($edge['failed']) }}</td>
                            <td class="is-num">{{ $edge['p95'] !== null ? Format::ms($edge['p95']) : '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</x-telemetry-ui::card>
