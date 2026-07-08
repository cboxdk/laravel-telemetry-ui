@use('Cbox\TelemetryUi\Support\Format')

<x-telemetry-ui::card title="Query detail" subtitle="One statement in depth — volume, latency, and who runs it" span="2">
    <div class="tui-toolbar" style="justify-content: space-between;">
        <a href="{{ $backUrl }}">← Queries</a>
        @if ($system !== '')
            <span class="tui-badge">{{ $system }}</span>
        @endif
    </div>

    @if ($error)
        <div class="tui-error">{{ $error }}</div>
    @elseif ($query === '')
        <div class="tui-empty">No statement selected.</div>
    @else
        <pre style="font-family: var(--font-mono, monospace); font-size: 12.5px; white-space: pre-wrap; word-break: break-word; background: rgba(255,255,255,.02); border: 1px solid var(--tui-border, #232326); border-radius: 8px; padding: 10px 12px; margin: 4px 0 12px;">{{ $query }}</pre>

        @if ($stats === null || $stats['calls'] === 0)
            <div class="tui-empty">No traces carrying this statement in the period.</div>
        @else
            <x-telemetry-ui::stats :items="[
                ['label' => 'Calls (sample)', 'value' => Format::count($stats['calls']), 'tone' => null],
                ['label' => 'Avg', 'value' => Format::ms($stats['avgMs']), 'tone' => null],
                ['label' => 'p95', 'value' => Format::ms($stats['p95Ms']), 'tone' => null],
                ['label' => 'Max', 'value' => Format::ms($stats['maxMs']), 'tone' => $stats['maxMs'] >= 500 ? 'warn' : null],
                ['label' => 'Total time', 'value' => Format::ms($stats['totalMs']), 'tone' => null],
            ]" />

            <div style="display: flex; align-items: center; gap: 10px; margin: 12px 0;">
                <span class="tui-note" style="margin: 0;">Latency trend</span>
                <x-telemetry-ui::sparkline :points="$trend" color="#8b5cf6" />
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                <div>
                    <div class="tui-note" style="margin: 0 0 6px;">Slowest example traces</div>
                    @if ($examples === [])
                        <div class="tui-empty">No traces.</div>
                    @else
                        <div class="tui-table-wrap">
                            <table class="tui-table">
                                <thead><tr><th>Origin</th><th class="is-num">Duration</th><th class="is-num">When</th></tr></thead>
                                <tbody>
                                    @foreach ($examples as $ex)
                                        <tr data-row-trace="{{ $ex['traceId'] }}">
                                            <td class="is-primary"><a class="tui-trace-link" data-trace-id="{{ $ex['traceId'] }}" href="{{ $this->traceUrl($ex['traceId']) }}">{{ $ex['origin'] }}</a></td>
                                            <td class="is-num {{ $ex['durationMs'] >= 500 ? 'tui-tone-warn' : '' }}">{{ Format::ms($ex['durationMs']) }}</td>
                                            <td class="is-num">{{ $ex['at']->format('H:i:s') }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>

                <div>
                    <div class="tui-note" style="margin: 0 0 6px;">Called by</div>
                    @if ($callers === [])
                        <div class="tui-empty">No callers.</div>
                    @else
                        <div class="tui-table-wrap">
                            <table class="tui-table">
                                <thead><tr><th>Route / job</th><th class="is-num">Calls</th><th class="is-num">Total</th></tr></thead>
                                <tbody>
                                    @foreach ($callers as $caller)
                                        <tr>
                                            <td class="is-primary">{{ $caller['origin'] }}</td>
                                            <td class="is-num">{{ Format::count($caller['calls']) }}</td>
                                            <td class="is-num is-primary">{{ Format::ms($caller['totalMs']) }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>
            </div>

            <div class="tui-note">Sampled from traces carrying this statement — a ClickHouse store aggregates every span exactly.</div>
        @endif
    @endif
</x-telemetry-ui::card>
