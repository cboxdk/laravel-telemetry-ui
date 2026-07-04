@use('Cbox\TelemetryUi\Support\Format')
@use('Cbox\TelemetryUi\Support\ServiceIdentity')

@if ($error !== null)
    <div class="tui-error">{{ $error }}</div>
@elseif ($trace === null || $rows === [])
    <div class="tui-empty">Trace not found.</div>
@else
    {{-- Any span/resource attribute is a drill-down: click a value (host, user,
         team, client IP, …) to see every trace that shares it — dimensional
         filtering straight from the properties window. --}}
    @php($filterable = fn ($key) => is_string($key) && preg_match('/^[a-zA-Z0-9_.]+$/', $key) === 1)
    @php($filterUrl = fn ($key, $value) => route('telemetry-ui.page', array_filter([
        'page' => 'traces',
        'q' => '{ .'.$key.' = "'.addcslashes((string) $value, '"\\').'" }',
        'service' => request('service'),
        'env' => request('env'),
        'period' => request('period'),
    ])))

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
                    @if (ServiceIdentity::kindLabel($hop['kind']))
                        <em>{{ ServiceIdentity::kindLabel($hop['kind']) }}</em>
                    @endif
                    <b>{{ Format::ms($hop['span']->durationMs()) }}</b>
                </span>
                @if (! $loop->last)
                    <span class="tui-chain-arrow">→</span>
                @endif
            @endforeach
        </div>
    @endif

    @if (! empty($context))
        @php($ctxValue = fn (string $unit, float $v) => match ($unit) {
            'ratio' => Format::percent($v),
            'bytes' => Format::bytes((int) $v),
            'bytes/s' => Format::bytes((int) $v).'/s',
            'ms' => Format::ms($v),
            default => rtrim(rtrim(number_format($v, 2), '0'), '.'),
        })
        <div class="tui-context">
            <span class="tui-context-label" title="Host &amp; runtime signals around this trace, vs. what's typical for this scope — the 'what was different?' view">Context</span>
            @foreach ($context as $sig)
                <div @class(['tui-context-tile', 'tui-ctx-'.$sig->group, 'is-outlier' => $sig->isOutlier()])>
                    <div class="tui-context-head">
                        <span class="tui-context-name">{{ $sig->label }}</span>
                        <span class="tui-context-val">{{ $ctxValue($sig->unit, $sig->current) }}@if ($sig->isOutlier())<span class="tui-context-flag" title="Well above its usual level here">⚠</span>@endif</span>
                    </div>
                    @if ($sig->baseline !== null)
                        <span class="tui-context-base">typ {{ $ctxValue($sig->unit, $sig->baseline) }}</span>
                    @endif
                    <x-telemetry-ui::sparkline :points="$sig->points" :color="$sig->isOutlier() ? '#f87171' : '#8b8b93'" />
                </div>
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
                        @if ($span->isBrowser())
                            <span class="tui-badge tui-badge-web" title="Ran in the browser (RUM) — frontend span">web</span>
                        @endif
                        @if ($span->serviceName !== '')
                            <span class="tui-badge" style="border-color: {{ $identity['color'] }}55; color: {{ $identity['color'] }}">
                                {{ $span->serviceName }}@if ($identity['label']) · {{ $identity['label'] }}@endif
                            </span>
                        @endif
                        <span class="name">{{ $span->name }}</span>
                        @if ($summary = $span->summary())
                            <span class="tui-wf-summary">{{ $summary }}</span>
                        @endif
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
                                <tr>
                                    <td>{{ $key }}</td>
                                    <td>
                                        @if ($filterable($key) && is_scalar($value) && (string) $value !== '')
                                            <a class="tui-attr-filter" href="{{ $filterUrl($key, $value) }}" title="Filter traces by {{ $key }}">{{ $value }}</a>
                                        @else
                                            {{ is_scalar($value) ? $value : json_encode($value) }}
                                        @endif
                                    </td>
                                </tr>
                            @endif
                        @endforeach
                        @foreach ($span->attributes as $key => $value)
                            <tr>
                                <td>{{ $key }}</td>
                                <td>
                                    @if ($filterable($key) && is_scalar($value) && (string) $value !== '')
                                        <a class="tui-attr-filter" href="{{ $filterUrl($key, $value) }}" title="Filter traces by {{ $key }}">{{ $value }}</a>
                                    @else
                                        {{ is_scalar($value) ? $value : json_encode($value) }}
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </table>
                </div>
            </div>
        @endforeach
    </div>
@endif
