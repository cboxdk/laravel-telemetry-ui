@use('Cbox\TelemetryUi\Support\Format')

<x-telemetry-ui::card title="Trace search" subtitle="Find requests, jobs and commands by status, route, name or duration" span="2">
    <x-slot:actions>
        <button type="button" class="tui-btn" wire:click="clearFilters">Clear</button>
    </x-slot:actions>

    @if ($error !== null)
        <div class="tui-error">{{ $error }}</div>
    @endif

    <div class="tui-filters" x-data="{ advanced: @js($usingRaw) }">
        <label class="tui-filter">
            <span>Status</span>
            <x-telemetry-ui::combobox wire:model.live="status">
                <option value="">Any</option>
                <option value="error">Errors</option>
                <option value="ok">OK</option>
            </x-telemetry-ui::combobox>
        </label>

        <label class="tui-filter">
            <span>Source</span>
            <x-telemetry-ui::combobox wire:model.live="source">
                <option value="">Any</option>
                <option value="frontend">Frontend (browser)</option>
                <option value="backend">Backend</option>
            </x-telemetry-ui::combobox>
        </label>

        <label class="tui-filter">
            <span>Status code</span>
            <x-telemetry-ui::combobox wire:model.live="statusCode">
                <option value="">Any</option>
                <option value="2xx">2xx</option>
                <option value="3xx">3xx</option>
                <option value="4xx">4xx</option>
                <option value="5xx">5xx</option>
            </x-telemetry-ui::combobox>
        </label>

        <label class="tui-filter">
            <span>Route</span>
            <input type="text" placeholder="/orders/{id}" wire:model.live.debounce.500ms="route" spellcheck="false">
        </label>

        <label class="tui-filter">
            <span>Path contains</span>
            <input type="text" placeholder="/checkout" wire:model.live.debounce.500ms="path" spellcheck="false">
        </label>

        <label class="tui-filter">
            <span>Client IP</span>
            <input type="text" placeholder="203.0.113.9" wire:model.live.debounce.500ms="ip" spellcheck="false">
        </label>

        <label class="tui-filter">
            <span>Name contains</span>
            <input type="text" placeholder="db.query, POST …" wire:model.live.debounce.500ms="nameContains" spellcheck="false">
        </label>

        <label class="tui-filter">
            <span>Min duration</span>
            <x-telemetry-ui::combobox wire:model.live="minDurationMs">
                @foreach ($durations as $duration)
                    <option value="{{ $duration }}">{{ $duration === 0 ? 'any' : $duration.'ms' }}</option>
                @endforeach
            </x-telemetry-ui::combobox>
        </label>

        <button type="button" class="tui-btn tui-advanced-toggle" x-on:click="advanced = !advanced"
                x-text="advanced ? 'Hide TraceQL' : 'Advanced (TraceQL)'"></button>

        <div class="tui-advanced" x-show="advanced" x-cloak>
            <input type="text" class="tui-input tui-input-grow"
                   placeholder='{ span.http.route = "/orders" && duration > 500ms }'
                   wire:model.live.debounce.700ms="query" spellcheck="false">
        </div>
    </div>

    <div class="tui-query-preview" title="The TraceQL sent to Tempo">{{ $effectiveQuery }}</div>

    @if ($results === [] && $error === null)
        <div class="tui-empty">No traces match these filters.</div>
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
                    @foreach ($results as $row)
                        <tr data-row-trace="{{ $row['traceId'] }}">
                            <td>{{ $row['startedAt']->format('H:i:s') }}</td>
                            <td>
                                <span class="tui-badge tui-badge-info">{{ $row['service'] }}</span>
                                @if ($row['browser'])<span class="tui-badge tui-badge-web" title="Browser (RUM)">web</span>@endif
                            </td>
                            <td class="is-primary">
                                <a class="tui-trace-link" data-trace-id="{{ $row['traceId'] }}" href="{{ $this->traceUrl($row['traceId']) }}">@if ($row['method'])<span class="tui-method">{{ $row['method'] }}</span> @endif{{ $row['target'] ?? $row['name'] }}</a>
                                @if ($row['status'])<span class="tui-badge {{ $row['isError'] ? 'tui-badge-danger' : 'tui-tone-dim' }}" title="HTTP status">{{ $row['status'] }}</span>@endif
                            </td>
                            <td class="is-num {{ $row['durationMs'] > 1000 ? 'tui-tone-warn' : '' }}">{{ Format::ms($row['durationMs']) }}</td>
                            <td class="is-num"><a class="tui-trace-link" data-trace-id="{{ $row['traceId'] }}" href="{{ $this->traceUrl($row['traceId']) }}">{{ substr($row['traceId'], 0, 8) }}…</a></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</x-telemetry-ui::card>
