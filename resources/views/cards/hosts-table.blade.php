@use('Cbox\TelemetryUi\Support\Format')

<x-telemetry-ui::card title="Hosts" subtitle="Every host reporting telemetry — request volume, errors, CPU, memory. Click a host for its detail page." span="2">
    @if ($error)
        <div class="tui-error">{{ $error }}</div>
    @elseif ($rows === [])
        <div class="tui-empty">No hosts reporting in this period.</div>
    @else
        <div class="tui-table-wrap">
            <table class="tui-table">
                <thead>
                    <tr>
                        <th>Host</th>
                        <th class="is-num">Requests</th>
                        <th class="is-num">5XX</th>
                        <th class="is-num">CPU</th>
                        <th class="is-num">Memory</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rows as $row)
                        <tr data-row-href="{{ $this->detailUrl($row['host']) }}">
                            <td class="is-primary"><a href="{{ $this->detailUrl($row['host']) }}" title="Open this host's detail page">{{ $row['host'] }}</a></td>
                            <td class="is-num is-primary"><a href="{{ $this->tracesUrl($row['host']) }}" x-on:click.stop title="Requests from this host">{{ Format::count($row['requests']) }}</a></td>
                            <td class="is-num {{ $row['errors'] > 0 ? 'tui-tone-danger' : '' }}">{{ Format::count($row['errors']) }}</td>
                            <td class="is-num">{{ $row['cpu'] !== null ? Format::percent($row['cpu']) : '—' }}</td>
                            <td class="is-num {{ ($row['memory'] ?? 0) > 0.9 ? 'tui-tone-warn' : '' }}">{{ $row['memory'] !== null ? Format::percent($row['memory']) : '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</x-telemetry-ui::card>
