@use('Cbox\TelemetryUi\Support\Format')

<x-telemetry-ui::card title="Recent traces" subtitle="Requests to this route — click a row for the waterfall + host context" span="2">
    @if ($error)
        <div class="tui-error">{{ $error }}</div>
    @elseif ($results === [])
        <div class="tui-empty">No traces for this route in this period.</div>
    @else
        <div class="tui-table-wrap">
            <table class="tui-table">
                <thead>
                    <tr>
                        <th>Time</th>
                        <th>Service</th>
                        <th>Trace</th>
                        <th class="is-num">Duration</th>
                        <th class="is-num">ID</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($results as $summary)
                        <tr data-row-trace="{{ $summary->traceId }}">
                            <td>{{ $summary->startedAt->format('H:i:s') }}</td>
                            <td><span class="tui-badge tui-badge-info">{{ $summary->rootServiceName }}</span></td>
                            <td class="is-primary"><a class="tui-trace-link" data-trace-id="{{ $summary->traceId }}" href="{{ $this->traceUrl($summary->traceId) }}">{{ $summary->rootTraceName ?: '(unnamed)' }}</a></td>
                            <td class="is-num {{ $summary->durationMs > 1000 ? 'tui-tone-warn' : '' }}">{{ Format::ms($summary->durationMs) }}</td>
                            <td class="is-num"><a class="tui-trace-link" data-trace-id="{{ $summary->traceId }}" href="{{ $this->traceUrl($summary->traceId) }}">{{ substr($summary->traceId, 0, 8) }}…</a></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</x-telemetry-ui::card>
