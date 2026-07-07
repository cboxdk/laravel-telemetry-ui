@use('Cbox\TelemetryUi\Support\Format')

<x-telemetry-ui::card title="Page performance" subtitle="Real-user navigation timings from the browser (RUM), grouped by page." span="2">
    @if ($error)
        <div class="tui-error">{{ $error }}</div>
    @elseif ($rows === [])
        <div class="tui-empty">No browser page loads in this period. RUM runs under the app's own service — check the service scope, and that the frontend SDK (@telemetryBrowser) is enabled.</div>
    @else
        <x-telemetry-ui::stats :items="$stats" />

        <div class="tui-table-wrap">
            <table class="tui-table">
                <thead>
                    <tr>
                        <th>Page</th>
                        <th class="is-num">Loads</th>
                        <th class="is-num">Avg load</th>
                        <th class="is-num">TTFB</th>
                        <th class="is-num">DOM interactive</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rows as $row)
                        <tr data-row-href="{{ $this->pageDetailUrl($row['path']) }}" title="Open this page's detail">
                            <td class="is-primary is-wide">{{ $row['path'] }}</td>
                            <td class="is-num">{{ Format::count($row['loads']) }}</td>
                            <td class="is-num">{{ Format::ms($row['loadMs']) }}</td>
                            <td class="is-num tui-tone-dim">{{ Format::ms($row['ttfb']) }}</td>
                            <td class="is-num tui-tone-dim">{{ Format::ms($row['dom']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</x-telemetry-ui::card>
