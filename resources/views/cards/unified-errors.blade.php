@use('Cbox\TelemetryUi\Support\Format')

<x-telemetry-ui::card title="Errors" subtitle="Every exception — frontend and backend — grouped by fingerprint. Click a row for stacktrace, occurrences and root-cause hints." span="2">
    @if ($error)
        <div class="tui-error">{{ $error }}</div>
    @elseif ($rows === [] && $search === '' && $sourceFilter === '')
        <div class="tui-empty">No errors in this period. 🎉</div>
    @else
        <div class="tui-toolbar">
            <input type="search" class="tui-input tui-input-grow" placeholder="Filter by type or message…"
                   wire:model.live.debounce.400ms="search">
            <select class="tui-input" style="min-width: 110px;" wire:model.live="sourceFilter" title="Source">
                <option value="">All sources</option>
                <option value="backend">Server</option>
                <option value="frontend">Web</option>
                <option value="full-stack">Full-stack</option>
            </select>
            <span class="tui-chain-label">Sort</span>
            @foreach (['count' => 'Events', 'last' => 'Last seen', 'new' => 'First seen'] as $key => $label)
                <button type="button" class="tui-btn tui-btn-sm {{ $sort === $key ? 'is-sort-active' : '' }}"
                        wire:click="$set('sort', '{{ $key }}')">{{ $label }}</button>
            @endforeach
            @if ($sampled)
                <span class="tui-note" style="padding: 0; margin-left: auto;">Sampled — counts are lower bounds.</span>
            @endif
        </div>

        @if ($rows === [])
            <div class="tui-empty">No errors match the filter.</div>
        @endif

        <div class="tui-table-wrap" @if ($rows === []) style="display: none" @endif>
            <table class="tui-table">
                <thead>
                    <tr>
                        <th>Source</th>
                        <th>Error</th>
                        <th>Trend</th>
                        <th class="is-num">Events</th>
                        <th class="is-num">Users</th>
                        <th class="is-num">First seen</th>
                        <th class="is-num">Last seen</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rows as $row)
                        <tr data-row-href="{{ $this->showUrl($row['group']) }}" title="Open this issue's page — trend, tags, stacktrace, root cause">
                            <td>
                                @if ($row['source'] === 'frontend')
                                    <span class="tui-badge tui-badge-web" title="Browser (RUM) error">web</span>
                                @elseif ($row['source'] === 'full-stack')
                                    <span class="tui-badge tui-badge-web" title="Seen in both browser and backend">full-stack</span>
                                @else
                                    <span class="tui-badge tui-badge-info" title="Backend error">server</span>
                                @endif
                            </td>
                            <td class="is-primary is-wide">
                                <span class="tui-err-type">
                                    {{ $row['type'] !== '' ? $row['type'] : $row['group'] }}
                                    @if ($row['isNew'])
                                        <span class="tui-badge tui-badge-danger" title="First seen within the last 24 hours">NEW</span>
                                    @endif
                                </span>
                                @if ($row['message'] !== '')
                                    <span class="tui-err-msg">{{ Str::limit($row['message'], 120) }}</span>
                                @endif
                            </td>
                            <td><x-telemetry-ui::sparkline :points="$row['buckets']" color="#f87171" /></td>
                            <td class="is-num tui-tone-danger">{{ Format::count($row['count']) }}</td>
                            <td class="is-num">{{ $row['users'] > 0 ? Format::count($row['users']) : '—' }}</td>
                            <td class="is-num tui-tone-dim">{{ $row['firstSeen'] }}</td>
                            <td class="is-num tui-tone-dim">{{ $row['lastSeen'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</x-telemetry-ui::card>
