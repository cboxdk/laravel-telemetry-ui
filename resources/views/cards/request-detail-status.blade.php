<x-telemetry-ui::card title="Status codes" subtitle="Exact response codes for this route — where errors concentrate">
    @if ($error)
        <div class="tui-error">{{ $error }}</div>
    @elseif ($rows === [])
        <div class="tui-empty">No requests in this period.</div>
    @else
        <div class="tui-table-wrap">
            <table class="tui-table">
                <thead>
                    <tr>
                        <th>Code</th>
                        <th>Share</th>
                        <th class="is-num">Count</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rows as $row)
                        @php($tone = $row['class'] === '5' ? 'tui-tone-danger' : ($row['class'] === '4' ? 'tui-tone-warn' : ''))
                        @php($color = $row['class'] === '5' ? '#f87171' : ($row['class'] === '4' ? '#fbbf24' : '#52525b'))
                        <tr>
                            <td class="is-primary {{ $tone }}">{{ $row['code'] }}</td>
                            <td>
                                <span style="display:inline-block;height:8px;border-radius:2px;background:{{ $color }};width:{{ $max > 0 ? max(4, round($row['count'] / $max * 160)) : 4 }}px"></span>
                            </td>
                            <td class="is-num is-primary">{{ $formatCount($row['count']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</x-telemetry-ui::card>
