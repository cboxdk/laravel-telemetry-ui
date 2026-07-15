@use('Cbox\TelemetryUi\Support\Format')

<x-telemetry-ui::card :title="$title" span="2">
    @if ($error)
        <div class="tui-error">{{ $error }}</div>
    @elseif ($rows === [])
        <div class="tui-empty">No data in this period.</div>
    @else
        <div class="tui-table-wrap">
            <table class="tui-table">
                <thead>
                    <tr>
                        @foreach ($keyColumns as $col)
                            <th>{{ $col }}</th>
                        @endforeach
                        <th class="is-num">{{ $valueColumn }}</th>
                        <th class="is-num">Share</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rows as $row)
                        <tr>
                            @foreach (array_values($row['keys']) as $i => $val)
                                <td class="{{ $i === 0 ? 'is-primary is-wide' : '' }}">{{ $val }}</td>
                            @endforeach
                            <td class="is-num">{{ Format::count($row['count']) }}</td>
                            <td class="is-num">{{ Format::percent($row['share']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</x-telemetry-ui::card>
