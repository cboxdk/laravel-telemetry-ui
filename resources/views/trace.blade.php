@use('Cbox\TelemetryUi\Support\Format')

<x-telemetry-ui::layout :pages="$pages" active="traces" :services="$services" :environments="$environments" :title="'Trace '.substr($traceId, 0, 8)">
    <div class="tui-crumbs">
        <a href="{{ route('telemetry-ui.page', array_filter(['page' => 'traces', 'period' => request('period'), 'service' => request('service'), 'env' => request('env')])) }}">Traces</a>
        <span> / {{ $traceId }}</span>
    </div>

    @if ($error !== null)
        <header class="tui-header"><h1>Trace</h1></header>
        <div class="tui-card"><div class="tui-card-body"><div class="tui-error">{{ $error }}</div></div></div>
    @elseif ($trace === null || $rows === [])
        <header class="tui-header"><h1>Trace</h1></header>
        <div class="tui-card"><div class="tui-card-body"><div class="tui-empty">Trace not found.</div></div></div>
    @else
        @php($root = $trace->root())
        @php($serviceCount = count(array_unique(array_map(fn ($r) => $r['span']->serviceName, $rows))))

        <header class="tui-header">
            <h1>{{ $root?->name ?: 'Trace' }}</h1>
        </header>

        <div class="tui-card tui-span-2">
            <div class="tui-card-body">
                <div class="tui-trace-meta">
                    <x-telemetry-ui::stats :items="[
                        ['label' => 'Duration', 'value' => Format::ms($trace->durationMs()), 'tone' => null],
                        ['label' => 'Spans', 'value' => (string) count($rows), 'tone' => 'dim'],
                        ['label' => 'Services', 'value' => (string) $serviceCount, 'tone' => 'dim'],
                        ['label' => 'Status', 'value' => $trace->hasError() ? 'ERROR' : 'OK', 'tone' => $trace->hasError() ? 'danger' : 'ok'],
                        ['label' => 'Started', 'value' => date('H:i:s', intdiv($rows[0]['span']->startNano, 1_000_000_000)), 'tone' => 'dim'],
                    ]" />
                </div>

                <div class="tui-waterfall">
                    @foreach ($rows as $row)
                        @php($span = $row['span'])
                        <div x-data="{ open: false }">
                            <div class="tui-wf-row" x-on:click="open = !open">
                                <div class="tui-wf-name {{ $span->hasError ? 'has-error' : '' }}" style="padding-left: {{ $row['depth'] * 14 }}px">
                                    @if ($span->serviceName !== '')
                                        <span class="tui-badge">{{ $span->serviceName }}</span>
                                    @endif
                                    <span class="name">{{ $span->name }}</span>
                                </div>
                                <div class="tui-wf-track">
                                    <div class="tui-wf-bar {{ $span->hasError ? 'is-error' : 'is-'.$span->kind->value }}"
                                         style="left: {{ number_format($row['offsetPct'], 3) }}%; width: {{ number_format($row['widthPct'], 3) }}%"></div>
                                </div>
                                <div class="tui-wf-duration">{{ Format::ms($span->durationMs()) }}</div>
                            </div>
                            <div class="tui-wf-attrs" x-show="open" x-cloak>
                                <table>
                                    <tr><td>span.id</td><td>{{ $span->spanId }}</td></tr>
                                    <tr><td>kind</td><td>{{ $span->kind->value }}</td></tr>
                                    @foreach ($span->attributes as $key => $value)
                                        <tr>
                                            <td>{{ $key }}</td>
                                            <td>{{ is_scalar($value) ? $value : json_encode($value) }}</td>
                                        </tr>
                                    @endforeach
                                </table>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    @endif
</x-telemetry-ui::layout>
