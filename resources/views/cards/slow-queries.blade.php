@use('Cbox\TelemetryUi\Support\Format')

<x-telemetry-ui::card title="Slowest queries" subtitle="Individual DB query spans sampled from traces — click to open the full trace" span="2">
    @if ($error)
        <div class="tui-error">{{ $error }}</div>
    @else
        <div class="tui-toolbar">
            <label class="tui-scope-field" style="padding: 0; flex-direction: row; align-items: center; gap: 8px;">
                <span>Slower than</span>
                <select wire:model.live="minMs">
                    @foreach ($thresholds as $threshold)
                        <option value="{{ $threshold }}">{{ $threshold }}ms</option>
                    @endforeach
                </select>
            </label>
        </div>

        @if ($rows === [])
            <div class="tui-empty">No query spans above {{ $minMs }}ms in this period.</div>
        @else
            <div class="tui-table-wrap">
                <table class="tui-table">
                    <thead>
                        <tr>
                            <th>Query</th>
                            <th>Origin</th>
                            <th class="is-num">Duration</th>
                            <th class="is-num">When</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $row)
                            <tr>
                                <td class="is-primary is-wide"><a href="{{ $this->traceUrl($row['traceId']) }}" title="Open trace">{{ Str::limit($row['query'], 160) }}</a></td>
                                <td>{{ $row['origin'] }}</td>
                                <td class="is-num tui-tone-warn">{{ Format::ms($row['durationMs']) }}</td>
                                <td class="is-num">{{ $row['startedAt']->format('H:i:s') }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="tui-note">Sampled from the most recent matching traces (Tempo search). Query text is truncated and redacted at emit time.</div>
        @endif
    @endif
</x-telemetry-ui::card>
