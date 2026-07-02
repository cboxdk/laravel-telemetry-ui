<x-telemetry-ui::card title="Logs" span="2">
    @if ($error)
        <div class="tui-error">{{ $error }}</div>
    @else
        <div class="tui-toolbar">
            <input type="search" class="tui-input tui-input-grow" placeholder="Filter log lines…" wire:model.live.debounce.400ms="search">
        </div>

        @if ($entries === [])
            <div class="tui-empty">No log lines in this period. Route the "telemetry" log channel to OTLP to see logs here.</div>
        @else
            <div class="tui-logs">
                @foreach ($entries as $entry)
                    <div class="tui-log-line">
                        <span class="tui-log-time">{{ $entry->timestamp()->format('H:i:s.v') }}</span>
                        <span class="tui-log-level tui-tone-{{ $this->level($entry) }}">{{ $entry->labels['level'] ?? $entry->labels['detected_level'] ?? '' }}</span>
                        <span class="tui-log-msg">{!! $this->linkify($entry->line) !!}</span>
                    </div>
                @endforeach
            </div>
            <div class="tui-note">Most recent 200 lines, oldest first.</div>
        @endif
    @endif
</x-telemetry-ui::card>
