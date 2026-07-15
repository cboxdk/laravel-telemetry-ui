<x-telemetry-ui::card title="Traffic by" subtitle="Requests grouped by a span attribute (user, guard, IP or custom), sampled from traces" span="2">
    <div class="tui-toolbar">
        <label class="tui-scope-field" style="padding: 0; flex-direction: row; align-items: center; gap: 8px;">
            <span>Facet</span>
            <x-telemetry-ui::combobox wire:model.live="facet">
                @foreach ($facets as $key => $label)
                    <option value="{{ $key }}">{{ $label }}</option>
                @endforeach
                <option value="custom">Custom attribute…</option>
            </x-telemetry-ui::combobox>
        </label>

        @if ($facet === 'custom')
            <input type="text" class="tui-input" placeholder="span attribute, e.g. team.id"
                   wire:model.live.debounce.500ms="customAttribute" spellcheck="false">
        @endif
    </div>

    @if ($error)
        <div class="tui-error">{{ $error }}</div>
    @elseif ($rows === [])
        <div class="tui-empty">No traces carrying this attribute in the period.</div>
    @else
        <div class="tui-table-wrap">
            <table class="tui-table">
                <thead>
                    <tr>
                        <th>{{ $valueColumn }}</th>
                        <th class="is-num">Traces (sampled)</th>
                        <th class="is-num">Errors (sampled)</th>
                        <th>Last action</th>
                        <th class="is-num">Last seen</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rows as $row)
                        <tr data-row-href="{{ $this->tracesUrl($row['value']) }}">
                            <td class="is-primary"><a href="{{ $this->tracesUrl($row['value']) }}" title="View traces">{{ $row['value'] }}</a></td>
                            <td class="is-num">{{ $row['traces'] }}</td>
                            <td class="is-num {{ $row['errors'] > 0 ? 'tui-tone-danger' : '' }}">{{ $row['errors'] }}</td>
                            <td>{{ $row['lastAction'] }}</td>
                            <td class="is-num">{{ $row['lastSeen']->format('H:i:s') }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="tui-note">Sampled from the most recent 100 matching traces per column — trends, not exact counts.</div>
    @endif
</x-telemetry-ui::card>
