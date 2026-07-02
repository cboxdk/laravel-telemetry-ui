@use('Cbox\TelemetryUi\Support\Format')

<x-telemetry-ui::card title="Exceptions by class" span="2">
    <x-slot:actions>
        <a class="tui-btn" href="{{ $errorTracesUrl }}">View error traces →</a>
    </x-slot:actions>

    @if ($error)
        <div class="tui-error">{{ $error }}</div>
    @elseif ($rows === [])
        <div class="tui-empty">No exceptions reported in this period. 🎉</div>
    @else
        <div class="tui-table-wrap">
            <table class="tui-table">
                <thead>
                    <tr>
                        <th>Exception</th>
                        <th class="is-num">Count</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rows as $row)
                        <tr>
                            <td class="is-primary is-wide">{{ $row['exception'] }}</td>
                            <td class="is-num tui-tone-danger">{{ Format::count($row['count']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</x-telemetry-ui::card>
