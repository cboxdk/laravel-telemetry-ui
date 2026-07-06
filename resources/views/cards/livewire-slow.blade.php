@use('Cbox\TelemetryUi\Support\Format')

<x-telemetry-ui::card title="Slowest components" subtitle="Livewire render/update/call spans sampled from traces — click to open the full trace" span="2">
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
            <div class="tui-empty">No Livewire spans above {{ $minMs }}ms in this period.</div>
        @else
            <div class="tui-table-wrap">
                <table class="tui-table">
                    <thead>
                        <tr>
                            <th>Component</th>
                            <th>Phase</th>
                            <th>Method / property</th>
                            <th class="is-num">Duration</th>
                            <th class="is-num">When</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $row)
                            <tr data-row-trace="{{ $row['traceId'] }}">
                                <td class="is-primary is-wide"><a class="tui-trace-link" data-trace-id="{{ $row['traceId'] }}" href="{{ $this->traceUrl($row['traceId']) }}" title="Open trace">{{ $row['component'] }}</a></td>
                                <td><span class="tui-badge">{{ $row['phase'] }}</span></td>
                                <td>{{ $row['detail'] !== '' ? $row['detail'] : '—' }}</td>
                                <td class="is-num tui-tone-warn">{{ Format::ms($row['durationMs']) }}</td>
                                <td class="is-num">{{ $row['startedAt']->format('H:i:s') }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="tui-note">Detail spans are tail-sampled — only slow/sampled requests carry them.</div>
        @endif
    @endif
</x-telemetry-ui::card>
