@use('Cbox\TelemetryUi\Support\Format')

<x-telemetry-ui::card title="Jobs" subtitle="Per-job outcomes and duration — click a job for its traces" span="2">
    @if ($error)
        <div class="tui-error">{{ $error }}</div>
    @else
        <div class="tui-toolbar">
            <input type="search" class="tui-input" placeholder="Search jobs…" wire:model.live.debounce.300ms="search">
        </div>

        @if ($rows === [])
            <div class="tui-empty">No jobs in this period.</div>
        @else
            <div class="tui-table-wrap">
                <table class="tui-table">
                    <thead>
                        <tr>
                            <th>Job</th>
                            <th>Queue</th>
                            <th class="is-num">Processed</th>
                            <th class="is-num">Released</th>
                            <th class="is-num">Failed</th>
                            <th class="is-num">AVG</th>
                            <th class="is-num">P95</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $row)
                            <tr>
                                <td class="is-primary"><a href="{{ $this->tracesUrl($row['job']) }}" title="View traces">{{ $row['job'] }}</a></td>
                                <td><span class="tui-badge">{{ $row['queue'] }}</span></td>
                                <td class="is-num">{{ Format::count($row['processed']) }}</td>
                                <td class="is-num {{ $row['released'] > 0 ? 'tui-tone-warn' : '' }}">{{ Format::count($row['released']) }}</td>
                                <td class="is-num {{ $row['failed'] > 0 ? 'tui-tone-danger' : '' }}">{{ Format::count($row['failed']) }}</td>
                                <td class="is-num">{{ $row['count'] > 0 ? Format::ms($row['time'] / $row['count']) : '—' }}</td>
                                <td class="is-num">{{ $row['p95'] !== null ? Format::ms($row['p95']) : '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    @endif
</x-telemetry-ui::card>
