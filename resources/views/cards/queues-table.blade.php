@use('Cbox\TelemetryUi\Support\Format')

<x-telemetry-ui::card title="Queues" subtitle="Per-queue backlog, drain rate, failure rate and attached workers — click a queue for its detail" span="2">
    @if ($error)
        <div class="tui-error">{{ $error }}</div>
    @else
        @if ($rows === [])
            <div class="tui-empty">No queues reporting.</div>
        @else
            <div class="tui-table-wrap">
                <table class="tui-table">
                    <thead>
                        <tr>
                            <th>Queue</th>
                            <th>Connection</th>
                            <th>Backlog trend</th>
                            <th class="is-num">Pending</th>
                            <th class="is-num">Oldest</th>
                            <th class="is-num">Jobs/min</th>
                            <th class="is-num">Failure</th>
                            <th class="is-num">Workers</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $row)
                            <tr data-row-href="{{ $this->detailUrl($row['queue']) }}">
                                <td class="is-primary"><a href="{{ $this->detailUrl($row['queue']) }}" title="Open queue detail">{{ $row['queue'] }}</a></td>
                                <td><span class="tui-badge">{{ $row['connection'] }}</span></td>
                                <td><x-telemetry-ui::sparkline :points="$row['spark'] ?? []" :color="$row['pending'] > 0 ? '#60a5fa' : '#71717a'" /></td>
                                <td class="is-num">{{ Format::count($row['pending']) }}</td>
                                <td class="is-num {{ $row['oldest'] >= 60 ? 'tui-tone-warn' : '' }}">{{ $row['oldest'] > 0 ? Format::ms($row['oldest'] * 1000) : '—' }}</td>
                                <td class="is-num">{{ Format::count($row['per_minute']) }}</td>
                                <td class="is-num {{ $row['failure'] > 0 ? 'tui-tone-danger' : '' }}">{{ Format::percent($row['failure'] / 100) }}</td>
                                <td class="is-num">{{ Format::count($row['workers']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    @endif
</x-telemetry-ui::card>
