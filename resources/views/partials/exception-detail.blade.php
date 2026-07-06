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
        @if (($stats['users'] ?? 0) > 0)
            <span><em>users affected</em> {{ $stats['users'] }}{{ $stats['sampled'] ? '+' : '' }}</span>
        @endif
        @if ($detail !== null && ($detail['environment'] ?? '') !== '')
            <span><em>env</em> {{ $detail['environment'] }}</span>
        @endif
        @if ($detail !== null && ($detail['release'] ?? '') !== '')
            <span><em>release</em> {{ $detail['release'] }}</span>
        @endif
        @if ($detail !== null && ($detail['host'] ?? '') !== '')
            <span><em>host</em> <a class="tui-attr-filter" href="{{ route('telemetry-ui.page', ['page' => 'host-detail', 'host' => $detail['host']]) }}" title="Open this host's detail page">{{ $detail['host'] }}</a></span>
        @endif
        @if ($detail !== null && $detail['file'] !== '')
            <span><em>at</em> {{ $detail['file'] }}:{{ $detail['line'] }}</span>
        @endif
        <span><em>group</em> {{ $group }}</span>
    </div>

    {{-- The request/job that hit it (Sentry's "which request?" panel), off
         the newest occurrence's trace root. Route links to its detail page;
         the trace itself stacks onto the pane. --}}
    @if ($request !== null)
        <div class="tui-issue-relations">
            <span class="tui-chain-label">Latest occurrence</span>
            @if ($request['route'] !== '')
                <a class="tui-chip" href="{{ route('telemetry-ui.page', ['page' => 'request-detail', 'route' => $request['route']]) }}" title="Open this route's detail page">
                    <em>{{ $request['method'] !== '' ? $request['method'] : 'route' }}</em> <b>{{ $request['route'] }}</b>
                </a>
            @elseif ($request['origin'] !== '')
                <span class="tui-chip"><em>origin</em> <b>{{ $request['origin'] }}</b></span>
            @endif
            @if ($request['status'] !== '')
                <span class="tui-chip"><em>status</em> <b class="{{ str_starts_with($request['status'], '5') ? 'tui-tone-danger' : '' }}">{{ $request['status'] }}</b></span>
            @endif
            @if ($request['user'] !== '')
                <span class="tui-chip"><em>user</em> <b>#{{ $request['user'] }}</b></span>
            @endif
            <a class="tui-chip tui-trace-link" data-trace-id="{{ $request['traceId'] }}" href="{{ route('telemetry-ui.trace', ['traceId' => $request['traceId']]) }}" title="Open the full request trace">⇄ {{ substr($request['traceId'], 0, 12) }}…</a>
        </div>
    @endif

    {{-- Root-cause hints: the change event closest before first-seen (a
         deploy/migration/flag the error was likely born from), and which
         releases the sampled occurrences carry. --}}
    @if ($suspect !== null || $releases !== [])
        <div class="tui-suspect">
            <span class="tui-chain-label">Root cause hints</span>
            @if ($suspect !== null)
                <div class="tui-suspect-row">
                    <span class="tui-annotation-dot" style="background: {{ $suspect['color'] }}"></span>
                    <span>
                        <strong>{{ $suspect['label'] }}</strong>
                        <span class="tui-tone-dim">at {{ $suspect['time'] }} — first seen {{ $suspect['gap'] }} later</span>
                        @if ($suspect['notes'])
                            <span class="tui-tone-dim">· {{ Str::limit($suspect['notes'], 80) }}</span>
                        @endif
                    </span>
                    @if ($suspect['traceId'])
                        <a class="tui-chip tui-trace-link" data-trace-id="{{ $suspect['traceId'] }}" href="{{ route('telemetry-ui.trace', ['traceId' => $suspect['traceId']]) }}">⇄ trace</a>
                    @endif
                </div>
            @endif
            @if ($releases !== [])
                <div class="tui-suspect-row">
                    <span class="tui-tone-dim" style="font-size: 11px;">Seen in:</span>
                    @foreach ($releases as $release)
                        <span class="tui-chip"><em>release</em> <b>{{ $release['release'] }}</b> · {{ $release['count'] }}</span>
                    @endforeach
                    @if (count($releases) === 1 && $stats['count'] > 1)
                        <span class="tui-badge tui-badge-warn" title="Every sampled occurrence carries this release — likely introduced by it">only this release</span>
                    @endif
                </div>
            @endif
        </div>
    @endif

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
                    <tr @if ($occurrence['traceId'] !== '') data-row-trace="{{ $occurrence['traceId'] }}" title="Open this occurrence's trace" @endif>
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
