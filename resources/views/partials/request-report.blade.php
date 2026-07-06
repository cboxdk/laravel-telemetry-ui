@use('Cbox\TelemetryUi\Support\Format')

{{-- The request, told as a story: facts, cost totals, then one readable
     section per concern. Raw span data stays in the collapsed waterfall. --}}

@if ($report['request'] !== [])
    <div class="tui-report-facts">
        @foreach ($report['request'] as $label => $value)
            <span class="tui-report-fact">
                <em>{{ $label }}</em>
                @if ($label === 'status')
                    <b class="{{ str_starts_with($value, '5') ? 'tui-tone-danger' : (str_starts_with($value, '4') ? 'tui-tone-warn' : 'tui-tone-ok') }}">{{ $value }}</b>
                @else
                    <b>{{ Str::limit($value, 90) }}</b>
                @endif
            </span>
        @endforeach
    </div>
@endif

@if ($report['totals'] !== [])
    <div class="tui-stats" style="padding-top: 4px;">
        @foreach ($report['totals'] as $total)
            <div class="tui-stat">
                <span class="tui-stat-label">{{ $total['label'] }}</span>
                <span class="tui-stat-value tui-tone-dim" style="font-size: 15px;">{{ $total['value'] }}</span>
            </div>
        @endforeach
    </div>
@endif

@if ($report['db']['items'] !== [])
    <h3 class="tui-section-title">Database · {{ count($report['db']['items']) }} queries</h3>
    @if ($report['db']['duplicates'] !== [])
        <div class="tui-note tui-tone-warn" style="padding: 0 0 6px;">
            ⚠ N+1: @foreach (array_slice($report['db']['duplicates'], 0, 3, true) as $sql => $count)<code>{{ Str::limit($sql, 60) }}</code> ×{{ $count }}{{ $loop->last ? '' : ' · ' }}@endforeach
        </div>
    @endif
    <div class="tui-table-wrap">
        <table class="tui-table">
            <tbody>
                @foreach (array_slice($report['db']['items'], 0, 15) as $item)
                    <tr>
                        <td class="is-primary is-wide">{{ Str::limit($item['detail'], 140) }}</td>
                        <td>{{ $item['name'] }}</td>
                        <td class="is-num {{ $item['durationMs'] > 50 ? 'tui-tone-warn' : 'tui-tone-dim' }}">{{ Format::ms($item['durationMs']) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif

@if ($report['outgoing'] !== [])
    <h3 class="tui-section-title">Upstream calls · {{ count($report['outgoing']) }}</h3>
    <div class="tui-table-wrap">
        <table class="tui-table">
            <tbody>
                @foreach (array_slice($report['outgoing'], 0, 10) as $item)
                    <tr>
                        <td class="is-primary is-wide">{{ Str::limit($item['detail'], 110) }}</td>
                        <td class="is-num">
                            @if ($item['name'] !== '')
                                <span class="tui-badge {{ str_starts_with($item['name'], '5') || str_starts_with($item['name'], '4') ? 'tui-badge-danger' : 'tui-badge-ok' }}">{{ $item['name'] }}</span>
                            @endif
                        </td>
                        <td class="is-num tui-tone-dim">{{ Format::ms($item['durationMs']) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif

@if ($report['cache']['items'] !== [])
    <h3 class="tui-section-title">
        Cache ·
        @foreach ($report['cache']['summary'] as $op => $count){{ $count }} {{ $op }}{{ $loop->last ? '' : ' · ' }}@endforeach
    </h3>
    <div class="tui-table-wrap">
        <table class="tui-table">
            <tbody>
                @foreach (array_slice($report['cache']['items'], 0, 10) as $item)
                    <tr>
                        <td><span class="tui-badge {{ $item['name'] === 'miss' ? 'tui-badge-warn' : ($item['name'] === 'hit' ? 'tui-badge-ok' : '') }}">{{ $item['name'] }}</span></td>
                        <td class="is-primary is-wide">{{ Str::limit($item['detail'], 110) }}</td>
                        <td class="is-num tui-tone-dim">{{ Format::ms($item['durationMs']) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif

@if ($report['redis'] !== [])
    <h3 class="tui-section-title">Redis · {{ count($report['redis']) }} commands</h3>
    <div class="tui-table-wrap">
        <table class="tui-table">
            <tbody>
                @foreach (array_slice($report['redis'], 0, 10) as $item)
                    <tr>
                        <td class="is-primary is-wide">{{ Str::limit($item['detail'], 110) }}</td>
                        <td class="is-num tui-tone-dim">{{ Format::ms($item['durationMs']) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif

@if ($report['queued'] !== [])
    <h3 class="tui-section-title">Dispatched jobs · {{ count($report['queued']) }}</h3>
    <div class="tui-table-wrap">
        <table class="tui-table">
            <tbody>
                @foreach ($report['queued'] as $item)
                    <tr>
                        <td class="is-primary is-wide">{{ $item['detail'] }}</td>
                        <td class="is-num tui-tone-dim">{{ Format::ms($item['durationMs']) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif

@if ($report['views'] !== [])
    <h3 class="tui-section-title">Views · {{ count($report['views']) }}</h3>
    <div class="tui-table-wrap">
        <table class="tui-table">
            <tbody>
                @foreach (array_slice($report['views'], 0, 8) as $item)
                    <tr>
                        <td class="is-primary is-wide">{{ $item['detail'] }}</td>
                        <td>{{ $item['name'] }}</td>
                        <td class="is-num tui-tone-dim">{{ Format::ms($item['durationMs']) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif

@if ($report['storage'] !== [])
    <h3 class="tui-section-title">Storage · {{ count($report['storage']) }}</h3>
    <div class="tui-table-wrap">
        <table class="tui-table">
            <tbody>
                @foreach ($report['storage'] as $item)
                    <tr>
                        <td class="is-primary is-wide">{{ $item['detail'] }}</td>
                        <td>{{ $item['name'] }}</td>
                        <td class="is-num tui-tone-dim">{{ Format::ms($item['durationMs']) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif

@if ($report['requestHeaders'] !== [] || $report['responseHeaders'] !== [])
    <h3 class="tui-section-title">Headers</h3>
    <div class="tui-report-headers">
        @foreach (['requestHeaders' => 'Request', 'responseHeaders' => 'Response'] as $key => $label)
            @if ($report[$key] !== [])
                <div>
                    <div class="tui-tagdist-head">{{ $label }}</div>
                    <table class="tui-headers-table">
                        @foreach ($report[$key] as $header => $value)
                            <tr><td>{{ $header }}</td><td>{{ Str::limit($value, 120) }}</td></tr>
                        @endforeach
                    </table>
                </div>
            @endif
        @endforeach
    </div>
@endif

@if (! empty($traceLogs))
    <h3 class="tui-section-title">Logs in this request · {{ count($traceLogs) }}</h3>
    <div class="tui-logs">
        @foreach ($traceLogs as $log)
            <div class="tui-log-line" style="cursor: default;">
                <span class="tui-log-time">{{ $log['time'] }}</span>
                <span class="tui-log-level tui-tone-{{ $log['tone'] }}">{{ $log['level'] }}</span>
                <span class="tui-log-msg">{{ $log['message'] }}</span>
            </div>
        @endforeach
    </div>
@endif
