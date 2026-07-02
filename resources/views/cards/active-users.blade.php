<x-telemetry-ui::card title="Recently active users" span="2">
    @if ($error)
        <div class="tui-error">{{ $error }}</div>
    @elseif ($users === [])
        <div class="tui-empty">No user-attributed traces in this period. Requires TELEMETRY_INSTRUMENT_USER=true (default on).</div>
    @else
        <div class="tui-table-wrap">
            <table class="tui-table">
                <thead>
                    <tr>
                        <th>User</th>
                        <th>Guard</th>
                        <th class="is-num">Traces (sampled)</th>
                        <th>Last action</th>
                        <th class="is-num">Last seen</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($users as $user)
                        <tr>
                            <td class="is-primary"><a href="{{ $this->tracesUrl($user['id']) }}" title="View traces">#{{ $user['id'] }}</a></td>
                            <td><span class="tui-badge">{{ $user['guard'] }}</span></td>
                            <td class="is-num">{{ $user['traces'] }}</td>
                            <td>{{ $user['lastAction'] }}</td>
                            <td class="is-num">{{ $user['lastSeen']->format('H:i:s') }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="tui-note">Sampled from the most recent 100 user-attributed traces — not a complete count.</div>
    @endif
</x-telemetry-ui::card>
