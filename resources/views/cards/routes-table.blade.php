@use('Cbox\TelemetryUi\Support\Format')

<x-telemetry-ui::card title="Routes" subtitle="Per-route request volume, status mix and latency — click a route for its traces" span="2">
    @if ($error)
        <div class="tui-error">{{ $error }}</div>
    @else
        <div class="tui-toolbar">
            <input type="search" class="tui-input" placeholder="Search routes…" wire:model.live.debounce.300ms="search">
        </div>

        @if ($rows === [])
            <div class="tui-empty">No requests in this period.</div>
        @else
            <div class="tui-table-wrap">
                <table class="tui-table">
                    <thead>
                        <tr>
                            <th>Method</th>
                            <th>Route</th>
                            <th class="is-num">1/2/3XX</th>
                            <th class="is-num">4XX</th>
                            <th class="is-num">5XX</th>
                            <th class="is-num">Total</th>
                            <th class="is-num">AVG</th>
                            <th class="is-num">P95</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $row)
                            <tr>
                                <td><span class="tui-badge {{ $row['method'] === 'GET' ? 'tui-badge-info' : 'tui-badge-ok' }}">{{ $row['method'] }}</span></td>
                                <td class="is-primary"><a href="{{ $this->tracesUrl($row['route']) }}" title="View traces">{{ $row['route'] }}</a></td>
                                <td class="is-num">{{ Format::count($row['ok']) }}</td>
                                <td class="is-num {{ $row['4xx'] > 0 ? 'tui-tone-warn' : '' }}">{{ Format::count($row['4xx']) }}</td>
                                <td class="is-num {{ $row['5xx'] > 0 ? 'tui-tone-danger' : '' }}">{{ Format::count($row['5xx']) }}</td>
                                <td class="is-num is-primary">{{ Format::count($row['total']) }}</td>
                                <td class="is-num">{{ $row['total'] > 0 ? Format::ms($row['time'] / $row['total']) : '—' }}</td>
                                <td class="is-num">{{ $row['p95'] !== null ? Format::ms($row['p95']) : '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    @endif
</x-telemetry-ui::card>
