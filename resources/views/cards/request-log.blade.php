@use('Cbox\TelemetryUi\Support\Format')

{{-- display:contents keeps the inner card a direct grid item, so its
     tui-span-2 spans the full row despite this Livewire/poll wrapper. --}}
<div style="display: contents" @if ($live) wire:poll.4s @endif>
<x-telemetry-ui::card title="Request log" subtitle="Individual requests, newest first — filter to a user or IP and go live to tail production" span="2">
    <x-slot:actions>
        <button type="button" class="tui-btn tui-btn-sm"
                wire:click="$dispatch('telemetry-ui:request-view-changed', { view: 'routes' })">Routes</button>
        <span class="tui-btn tui-btn-sm is-sort-active" style="cursor: default;">Request log</span>
        <button type="button" class="tui-btn tui-btn-sm {{ $live ? 'is-live' : '' }}"
                wire:click="$set('live', {{ $live ? 'false' : 'true' }})"
                title="{{ $live ? 'Stop tailing' : 'Re-poll every few seconds, newest on top' }}">
            {{ $live ? '● Live' : '○ Live' }}
        </button>
    </x-slot:actions>

    @if ($error)
        <div class="tui-error">{{ $error }}</div>
    @else
        <div class="tui-toolbar">
            <input type="text" class="tui-input" style="min-width: 130px;" placeholder="User id…"
                   wire:model.live.debounce.400ms="user" spellcheck="false">
            <input type="text" class="tui-input" style="min-width: 140px;" placeholder="Client IP…"
                   wire:model.live.debounce.400ms="ip" spellcheck="false">
            <input type="text" class="tui-input tui-input-grow" placeholder="Path contains…"
                   wire:model.live.debounce.400ms="path" spellcheck="false">
            <x-telemetry-ui::combobox class="tui-input" style="min-width: 90px;" wire:model.live="statusCode" title="Status class">
                <option value="">Any</option>
                <option value="2xx">2xx</option>
                <option value="3xx">3xx</option>
                <option value="4xx">4xx</option>
                <option value="5xx">5xx</option>
            </x-telemetry-ui::combobox>
        </div>

        @if ($rows === [])
            <div class="tui-empty">No requests match — widen the filters or the period.</div>
        @else
            <div class="tui-table-wrap">
                <table class="tui-table">
                    <thead>
                        <tr>
                            <th>Time</th>
                            <th>Request</th>
                            <th class="is-num">Status</th>
                            <th class="is-num">User</th>
                            <th class="is-num">IP</th>
                            <th class="is-num">Duration</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $row)
                            <tr data-row-trace="{{ $row['traceId'] }}" title="Open this request's story">
                                <td>{{ $row['startedAt']->format('H:i:s') }}</td>
                                <td class="is-primary is-wide">
                                    <span class="tui-method">{{ $row['method'] }}</span>
                                    {{ Str::limit($row['path'], 80) }}
                                </td>
                                <td class="is-num">
                                    @if ($row['status'] !== '')
                                        <span class="tui-badge {{ str_starts_with($row['status'], '5') ? 'tui-badge-danger' : (str_starts_with($row['status'], '4') ? 'tui-badge-warn' : 'tui-badge-ok') }}">{{ $row['status'] }}</span>
                                    @endif
                                </td>
                                <td class="is-num">
                                    @if ($row['user'] !== '')
                                        <button type="button" class="tui-attr-filter" style="background:none;border:0;padding:0;font:inherit;" wire:click="$set('user', '{{ $row['user'] }}')" title="Tail this user">#{{ $row['user'] }}</button>
                                    @else
                                        —
                                    @endif
                                </td>
                                <td class="is-num">
                                    @if ($row['ip'] !== '')
                                        <button type="button" class="tui-attr-filter" style="background:none;border:0;padding:0;font:inherit;" wire:click="$set('ip', '{{ $row['ip'] }}')" title="Tail this IP">{{ $row['ip'] }}</button>
                                    @else
                                        —
                                    @endif
                                </td>
                                <td class="is-num tui-tone-dim">{{ Format::ms($row['durationMs']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="tui-note">Newest 50 within the period. Click user/IP to tail them; click a row for the full request story.</div>
        @endif
    @endif
</x-telemetry-ui::card>
</div>
