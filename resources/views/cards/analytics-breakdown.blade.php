@use('Cbox\TelemetryUi\Support\Format')

<x-telemetry-ui::card title="Sources & audience" subtitle="Where visits come from and who they are. Countries need the emitter's geo lookup; devices need its User-Agent parsing." span="2">
    @if ($error)
        <div class="tui-error">{{ $error }}</div>
    @else
        <div class="tui-analytics-cols">
            @forelse ($sections as $section)
                <div class="tui-analytics-col">
                    <h4 class="tui-analytics-col-title">{{ $section['title'] }}</h4>
                    @if ($section['rows'] === [])
                        <p class="tui-tone-dim tui-analytics-empty">{{ $section['hint'] }}</p>
                    @else
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
                    @endif
                </div>
            @empty
                <p class="tui-tone-dim tui-analytics-empty">No analytics dimensions enabled — see <code>telemetry-ui.analytics.dimensions</code>.</p>
            @endforelse
        </div>
    @endif
</x-telemetry-ui::card>
