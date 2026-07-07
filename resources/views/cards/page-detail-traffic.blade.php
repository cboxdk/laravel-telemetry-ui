@use('Cbox\TelemetryUi\Support\Format')

<x-telemetry-ui::card title="Traffic" subtitle="Views over time, and where this page's visits come from." span="2">
    @if ($error)
        <div class="tui-error">{{ $error }}</div>
    @else
        @if ($series === [] || ! collect($series[0]['data'] ?? [])->contains(fn (array $p): bool => ($p[1] ?? 0) != 0))
            <div class="tui-empty">No page views in this period.</div>
        @else
            <x-telemetry-ui::chart :series="$series" type="bar" :height="180" :min="$min" :max="$max" />
        @endif

        <div class="tui-analytics-cols">
            @foreach ([['Referrers', $referrers, 'No referrer data yet.'], ['Countries', $countries, 'Set TELEMETRY_ANALYTICS_GEO=true (+ a GeoLite2 db) in the emitter to see countries.'], ['Devices', $devices, 'Set TELEMETRY_ANALYTICS_UA=true in the emitter to see devices.']] as [$title, $rows, $emptyHint])
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
