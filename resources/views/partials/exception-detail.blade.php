@use('Cbox\TelemetryUi\Support\Format')

@if ($error !== null)
    <div class="tui-error">{{ $error }}</div>
@elseif ($stats === null)
    <div class="tui-empty">No occurrences of this error group in the last {{ $lookbackDays }} days (within your scope).</div>
@else
    @if ($detail !== null && $detail['message'] !== '')
        <p class="tui-exception-message">{{ $detail['message'] }}</p>
    @endif

    <div class="tui-issue-facts">
        <span><em>occurrences</em> {{ Format::count($stats['count']) }}{{ $stats['sampled'] ? '+' : '' }}</span>
        <span><em>first seen</em> {{ $stats['firstSeen'] }}</span>
        <span><em>last seen</em> {{ $stats['lastSeen'] }}</span>
        <span><em>source</em> {{ $stats['source'] }}</span>
        @if ($detail !== null && $detail['file'] !== '')
            <span><em>at</em> {{ $detail['file'] }}:{{ $detail['line'] }}</span>
        @endif
        <span><em>group</em> {{ $group }}</span>
    </div>

    @if ($canCreate && $draft !== null)
        <div class="tui-issue-relations">
            <button type="button" class="tui-btn tui-btn-sm" x-data
                    x-on:click="window.Livewire.dispatch('telemetry-ui:compose-ticket', @js($draft))"
                    title="Create a ticket prefilled with this error">+ ticket</button>
        </div>
    @endif

    @if ($detail !== null && $detail['source'] !== '')
        <h3 class="tui-section-title">Source · {{ $detail['file'] }}:{{ $detail['line'] }}</h3>
        <pre class="tui-code">@foreach (explode("\n", $detail['source']) as $line)@if (str_starts_with($line, '> '))<span class="is-throw-line">{{ $line }}</span>@else{{ $line }}@endif{{ "\n" }}@endforeach</pre>
    @endif

    @if ($detail !== null && $detail['stacktrace'] !== '')
        <h3 class="tui-section-title">Stacktrace · latest occurrence</h3>
        <pre class="tui-code tui-stacktrace">{{ $detail['stacktrace'] }}</pre>
    @elseif ($stats['source'] === 'frontend')
        <div class="tui-note">Browser errors carry no stacktrace — the SDK ships type, message and file:line only.</div>
    @endif

    <h3 class="tui-section-title">Recent occurrences</h3>
    <div class="tui-table-wrap">
        <table class="tui-table">
            <thead>
                <tr>
                    <th>When</th>
                    <th>Source</th>
                    <th>Service</th>
                    <th class="is-wide">Message</th>
                    <th class="is-num">Trace</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($occurrences as $occurrence)
                    <tr>
                        <td>{{ $occurrence['at'] }}</td>
                        <td>
                            @if ($occurrence['frontend'])
                                <span class="tui-badge tui-badge-web">web</span>
                            @else
                                <span class="tui-badge tui-badge-info">server</span>
                            @endif
                        </td>
                        <td>{{ $occurrence['service'] }}</td>
                        <td class="is-wide">{{ Str::limit($occurrence['message'], 120) }}</td>
                        <td class="is-num">
                            @if ($occurrence['traceId'] !== '')
                                <a class="tui-trace-link" data-trace-id="{{ $occurrence['traceId'] }}" href="{{ route('telemetry-ui.trace', ['traceId' => $occurrence['traceId']]) }}">{{ substr($occurrence['traceId'], 0, 8) }}…</a>
                            @else
                                —
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <div class="tui-note">
        Occurrences and counts are a sample of the last {{ $lookbackDays }} days{{ $stats['sampled'] ? ' (capped — the real total is higher)' : '' }}.
    </div>
@endif
