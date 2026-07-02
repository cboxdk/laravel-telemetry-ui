@use('Cbox\TelemetryUi\Support\Format')

<x-telemetry-ui::card title="Trace search" span="2">
    @if ($error !== null)
        <div class="tui-error">{{ $error }}</div>
    @endif

    <div class="tui-toolbar">
        <input type="text" class="tui-input tui-input-grow" placeholder='TraceQL, e.g. { span.http.route = "/orders" && duration > 500ms }'
               wire:model.live.debounce.600ms="query" spellcheck="false">
        <button type="button" class="tui-btn {{ $errorsOnly ? 'is-active' : '' }}" wire:click="$toggle('errorsOnly')">Errors only</button>
        <label class="tui-scope-field" style="padding: 0; flex-direction: row; align-items: center; gap: 6px;">
            <span>Min duration</span>
            <select wire:model.live="minDurationMs">
                @foreach ($durations as $duration)
                    <option value="{{ $duration }}">{{ $duration === 0 ? 'any' : $duration.'ms' }}</option>
                @endforeach
            </select>
        </label>
    </div>

    <div class="tui-note" style="padding: 0 0 10px;">{{ $effectiveQuery }}</div>

    @if ($results === [] && $error === null)
        <div class="tui-empty">No traces match.</div>
    @elseif ($results !== [])
        <div class="tui-table-wrap">
            <table class="tui-table">
                <thead>
                    <tr>
                        <th>Time</th>
                        <th>Service</th>
                        <th>Root span</th>
                        <th class="is-num">Duration</th>
                        <th class="is-num">Trace</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($results as $summary)
                        <tr>
                            <td>{{ $summary->startedAt->format('H:i:s') }}</td>
                            <td><span class="tui-badge tui-badge-info">{{ $summary->rootServiceName }}</span></td>
                            <td class="is-primary"><a href="{{ $this->traceUrl($summary->traceId) }}">{{ $summary->rootTraceName ?: '(unnamed)' }}</a></td>
                            <td class="is-num {{ $summary->durationMs > 1000 ? 'tui-tone-warn' : '' }}">{{ Format::ms($summary->durationMs) }}</td>
                            <td class="is-num"><a href="{{ $this->traceUrl($summary->traceId) }}">{{ substr($summary->traceId, 0, 8) }}…</a></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</x-telemetry-ui::card>
