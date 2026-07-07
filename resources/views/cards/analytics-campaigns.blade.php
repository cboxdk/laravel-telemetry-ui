@use('Cbox\TelemetryUi\Support\Format')

<x-telemetry-ui::card title="Campaigns" subtitle="UTM campaign attribution from the landing URL — top campaigns, sources and mediums, with distinct visitors each." span="2">
    @if ($error)
        <div class="tui-error">{{ $error }}</div>
    @elseif (! $hasUtm)
        <div class="tui-empty">
            No campaign traffic in this period. Set <code>TELEMETRY_ANALYTICS_UTM=true</code> in the emitter
            (<code>cboxdk/laravel-telemetry</code> ≥ 0.3.0) to capture <code>utm_*</code> tags and paid-click sources.
        </div>
    @else
        <div class="tui-analytics-cols">
            @foreach ($sections as $section)
                @if ($section['rows'] !== [])
                    <div class="tui-analytics-col">
                        <h4 class="tui-analytics-col-title">{{ $section['title'] }}</h4>
                        <table class="tui-table">
                            <tbody>
                                @foreach ($section['rows'] as $row)
                                    <tr>
                                        <td class="is-primary">{{ $row['key'] }}</td>
                                        <td class="is-num">{{ Format::count($row['views']) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            @endforeach
        </div>
    @endif
</x-telemetry-ui::card>
