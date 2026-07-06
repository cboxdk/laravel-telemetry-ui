@use('Cbox\TelemetryUi\Support\Format')

<x-telemetry-ui::card title="Feature flags" subtitle="Pennant checks by flag and result over the period" span="2">
    @if ($error)
        <div class="tui-error">{{ $error }}</div>
    @elseif ($rows === [] && $unknown === [])
        <div class="tui-empty">No feature-flag checks in this period.</div>
    @else
        @if ($unknown !== [])
            <div class="tui-note tui-tone-warn" style="padding-bottom: 10px;">
                ⚠ Checks against unregistered flags (typo or stale flag):
                @foreach ($unknown as $flag)
                    <code>{{ $flag['feature'] }}</code> ({{ Format::count($flag['checks']) }}){{ $loop->last ? '' : ', ' }}
                @endforeach
            </div>
        @endif

        <div class="tui-table-wrap">
            <table class="tui-table">
                <thead>
                    <tr>
                        <th>Feature</th>
                        <th class="is-num">Checks</th>
                        <th class="is-num">Active</th>
                        <th class="is-wide">Results</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rows as $row)
                        @php($active = $row['results']['active'] ?? 0.0)
                        <tr>
                            <td class="is-primary">{{ $row['feature'] }}</td>
                            <td class="is-num">{{ Format::count($row['checks']) }}</td>
                            <td class="is-num">{{ $row['checks'] > 0 ? Format::percent($active / $row['checks']) : '—' }}</td>
                            <td class="is-wide">
                                @foreach ($row['results'] as $result => $count)
                                    <span class="tui-badge {{ $result === 'active' ? 'tui-badge-ok' : ($result === 'inactive' ? '' : 'tui-badge-info') }}">{{ $result }} · {{ Format::count($count) }}</span>
                                @endforeach
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</x-telemetry-ui::card>
