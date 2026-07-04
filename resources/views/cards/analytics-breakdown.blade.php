@use('Cbox\TelemetryUi\Support\Format')

<x-telemetry-ui::card title="Sources & audience" subtitle="Where visits come from and who they are. Countries need the emitter's geo lookup; devices need its User-Agent parsing." span="2">
    @if ($error)
        <div class="tui-error">{{ $error }}</div>
    @else
        <div class="tui-analytics-cols">
            @foreach ([['Referrers', $referrers, 'No referrer data yet.'], ['Countries', $countries, 'Enable geo in the emitter to see countries.'], ['Devices', $devices, 'Enable User-Agent parsing to see devices.']] as [$title, $rows, $emptyHint])
                <div class="tui-analytics-col">
                    <h4 class="tui-analytics-col-title">{{ $title }}</h4>
                    @if ($rows === [])
                        <p class="tui-tone-dim tui-analytics-empty">{{ $emptyHint }}</p>
                    @else
                        <table class="tui-table">
                            <tbody>
                                @foreach ($rows as $row)
                                    <tr>
                                        <td class="is-primary">{{ $row['key'] }}</td>
                                        <td class="is-num">{{ Format::count($row['views']) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    @endif
                </div>
            @endforeach
        </div>
    @endif
</x-telemetry-ui::card>
