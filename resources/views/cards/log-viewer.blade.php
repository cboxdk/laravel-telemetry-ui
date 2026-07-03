<x-telemetry-ui::card title="Logs" subtitle="Trace-correlated log lines from Loki — click a line for metadata, jump to its trace" span="2">
    @if ($error)
        <div class="tui-error">{{ $error }}</div>
    @else
        <div class="tui-toolbar">
            <select class="tui-input" style="min-width: 120px" wire:model.live="level">
                <option value="">All levels</option>
                @foreach ($levels as $lvl)
                    <option value="{{ $lvl }}">{{ ucfirst($lvl) }}</option>
                @endforeach
            </select>
            <input type="search" class="tui-input tui-input-grow" placeholder="Filter log lines…" wire:model.live.debounce.400ms="search">
        </div>

        @if ($rows === [])
            <div class="tui-empty">No log lines in this period. Route the "telemetry" log channel to OTLP to see logs here.</div>
        @else
            <div class="tui-logs">
                @foreach ($rows as $row)
                    <div class="tui-log-row" x-data="{ open: false }">
                        <div class="tui-log-line" x-on:click="open = !open">
                            <span class="tui-log-time">{{ $row['time'] }}</span>
                            <span class="tui-log-level tui-tone-{{ $row['tone'] }}">{{ $row['level'] }}</span>
                            @if ($row['service'] !== '')
                                <span class="tui-badge">{{ $row['service'] }}</span>
                            @endif
                            <span class="tui-log-msg">{{ $row['message'] }}</span>
                            @if ($row['traceUrl'])
                                <a class="tui-log-trace tui-trace-link" data-trace-id="{{ $row['traceId'] }}" href="{{ $row['traceUrl'] }}" x-on:click.stop title="Open trace">⇄ trace</a>
                            @endif
                        </div>
                        @if ($row['meta'] !== [] || $row['traceId'])
                            <div class="tui-log-meta" x-show="open" x-cloak>
                                <table>
                                    @if ($row['traceId'])
                                        <tr><td>trace_id</td><td><a href="{{ $row['traceUrl'] }}">{{ $row['traceId'] }}</a></td></tr>
                                    @endif
                                    @foreach ($row['meta'] as $key => $value)
                                        <tr><td>{{ $key }}</td><td>{{ $value }}</td></tr>
                                    @endforeach
                                </table>
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>
            <div class="tui-note">Most recent 200 lines, oldest first.</div>
        @endif
    @endif
</x-telemetry-ui::card>
