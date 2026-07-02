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

        <header class="tui-header">
            <h1>{{ $root?->name ?: 'Trace' }}</h1>
        </header>

        <div class="tui-card tui-span-2">
            <div class="tui-card-body">
                <div class="tui-trace-meta">
                    <x-telemetry-ui::stats :items="[
                        ['label' => 'Duration', 'value' => Format::ms($trace->durationMs()), 'tone' => null],
                        ['label' => 'Spans', 'value' => (string) count($rows), 'tone' => 'dim'],
                        ['label' => 'Services', 'value' => (string) count($identities), 'tone' => 'dim'],
                        ['label' => 'Status', 'value' => $trace->hasError() ? 'ERROR' : 'OK', 'tone' => $trace->hasError() ? 'danger' : 'ok'],
                        ['label' => 'Started', 'value' => date('H:i:s', intdiv($rows[0]['span']->startNano, 1_000_000_000)), 'tone' => 'dim'],
                    ]" />
                </div>

                @if (count($chain) > 1)
                    <div class="tui-chain">
                        <span class="tui-chain-label">Request chain</span>
                        @foreach ($chain as $hop)
                            <span class="tui-chip" style="border-color: {{ $hop['color'] }}66; color: {{ $hop['color'] }}">
                                {{ $hop['span']->serviceName }}
                                @if (Cbox\TelemetryUi\Support\ServiceIdentity::kindLabel($hop['kind']))
                                    <em>{{ Cbox\TelemetryUi\Support\ServiceIdentity::kindLabel($hop['kind']) }}</em>
                                @endif
                                <b>{{ Format::ms($hop['span']->durationMs()) }}</b>
                            </span>
                            @if (! $loop->last)
                                <span class="tui-chain-arrow">→</span>
                            @endif
                        @endforeach
                    </div>
                @endif

                <div class="tui-waterfall" x-data="{ collapsed: {} }">
                    @foreach ($rows as $row)
                        @php($span = $row['span'])
                        @php($identity = $identities[$span->serviceName] ?? ['color' => '#71717a', 'kind' => 'app', 'label' => null])
                        <div x-data="{ open: false }"
                             x-show="!@js($row['ancestors']).some(id => collapsed[id])">
                            <div class="tui-wf-row" x-on:click="open = !open">
                                <div class="tui-wf-name {{ $span->hasError ? 'has-error' : '' }}" style="padding-left: {{ $row['depth'] * 14 }}px">
                                    @if ($row['children'] > 0)
                                        <button type="button" class="tui-wf-caret"
                                                x-on:click.stop="collapsed['{{ $span->spanId }}'] = !collapsed['{{ $span->spanId }}']"
                                                x-text="collapsed['{{ $span->spanId }}'] ? '▸' : '▾'"></button>
                                    @else
                                        <span class="tui-wf-caret is-leaf"></span>
                                    @endif
                                    @if ($span->serviceName !== '')
                                        <span class="tui-badge" style="border-color: {{ $identity['color'] }}55; color: {{ $identity['color'] }}">
                                            {{ $span->serviceName }}@if ($identity['label']) · {{ $identity['label'] }}@endif
                                        </span>
                                    @endif
                                    <span class="name">{{ $span->name }}</span>
                                    <span x-show="collapsed['{{ $span->spanId }}']" x-cloak class="tui-wf-collapsed-count">+{{ $row['children'] }}</span>
                                </div>
                                <div class="tui-wf-track">
                                    <div class="tui-wf-bar {{ $span->hasError ? 'is-error' : 'is-'.$span->kind->value }}"
                                         style="left: {{ number_format($row['offsetPct'], 3) }}%; width: {{ number_format($row['widthPct'], 3) }}%; background: {{ $span->hasError ? '' : $identity['color'] }}"></div>
                                </div>
                                <div class="tui-wf-duration">{{ Format::ms($span->durationMs()) }}</div>
                            </div>
                            <div class="tui-wf-attrs" x-show="open" x-cloak>
                                <table>
                                    <tr><td>span.id</td><td>{{ $span->spanId }}</td></tr>
                                    <tr><td>kind</td><td>{{ $span->kind->value }}</td></tr>
                                    @foreach ($trace->services[$span->serviceName] ?? [] as $key => $value)
                                        @if (in_array($key, ['telemetry.sdk.name', 'service.version', 'deployment.id', 'deployment.environment.name'], true))
                                            <tr><td>{{ $key }}</td><td>{{ is_scalar($value) ? $value : json_encode($value) }}</td></tr>
                                        @endif
                                    @endforeach
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
