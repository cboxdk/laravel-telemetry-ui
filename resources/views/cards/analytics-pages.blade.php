@use('Cbox\TelemetryUi\Support\Format')

<x-telemetry-ui::card title="Top pages" subtitle="Most-viewed pages, with distinct visitors each." span="2">
    @if ($error)
        <div class="tui-error">{{ $error }}</div>
    @elseif ($rows === [])
        <div class="tui-empty">No page views in this period. Analytics runs under the app's own service — check the service scope.</div>
    @else
        <div class="tui-table-wrap">
            <table class="tui-table">
                <thead>
                    <tr>
                        <th>Page</th>
                        <th class="is-num">Views</th>
                        <th class="is-num">Visitors</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rows as $row)
                        <tr data-row-href="{{ $this->pageDetailUrl($row['key']) }}" title="Open this page's detail">
                            <td class="is-primary is-wide">{{ $row['key'] }}</td>
                            <td class="is-num">{{ Format::count($row['views']) }}</td>
                            <td class="is-num tui-tone-dim">{{ Format::count($row['visitors']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</x-telemetry-ui::card>
