@use('Cbox\TelemetryUi\Support\Format')

<x-telemetry-ui::card
    title="Query performance"
    subtitle="DB queries aggregated by statement — ranked by the DB time they consume in total, not just the single slowest run"
    span="2"
>
    @if ($error)
        <div class="tui-error">{{ $error }}</div>
    @else
        <div class="tui-toolbar">
            <input type="search" class="tui-input tui-input-grow" placeholder="Filter queries…" wire:model.live.debounce.300ms="search" />

            <label class="tui-scope-field" style="padding: 0; flex-direction: row; align-items: center; gap: 8px;">
                <span>Slower than</span>
                <select wire:model.live="minMs">
                    @foreach ($thresholds as $threshold)
                        <option value="{{ $threshold }}">{{ $threshold === 0 ? 'All' : $threshold.'ms' }}</option>
                    @endforeach
                </select>
            </label>

            <label class="tui-scope-field" style="padding: 0; flex-direction: row; align-items: center; gap: 8px;">
                <span>Rank by</span>
                <select wire:model.live="sort">
                    <option value="total">Total time</option>
                    <option value="avg">Avg</option>
                    <option value="p95">p95</option>
                    <option value="max">Max</option>
                    <option value="calls">Calls</option>
                </select>
            </label>
        </div>

        @if ($rows === [])
            <div class="tui-empty">No database queries in this period.</div>
        @else
            <div class="tui-table-wrap">
                <table class="tui-table">
                    <thead>
                        <tr>
                            <th>Query</th>
                            <th>DB</th>
                            <th class="is-num">Calls</th>
                            <th class="is-num">Avg</th>
                            <th class="is-num">p95</th>
                            <th class="is-num">Max</th>
                            <th class="is-num">Total</th>
                            <th class="is-num">Share</th>
                            <th>Trend</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $row)
                            <tr data-row-href="{{ $this->detailUrl($row['query']) }}">
                                <td class="is-primary is-wide">
                                    <a href="{{ $this->detailUrl($row['query']) }}" title="{{ $row['query'] }}">{{ Str::limit($row['query'], 140) }}</a>
                                </td>
                                <td>{{ $row['system'] }}</td>
                                <td class="is-num">{{ Format::count($row['calls']) }}</td>
                                <td class="is-num">{{ Format::ms($row['avgMs']) }}</td>
                                <td class="is-num">{{ Format::ms($row['p95Ms']) }}</td>
                                <td class="is-num {{ $row['maxMs'] >= 500 ? 'tui-tone-warn' : '' }}">{{ Format::ms($row['maxMs']) }}</td>
                                <td class="is-num is-primary">{{ Format::ms($row['totalMs']) }}</td>
                                <td class="is-num">{{ Format::percent($row['share']) }}</td>
                                <td><x-telemetry-ui::sparkline :points="$row['spark']" color="#8b5cf6" /></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="tui-note">
                @if ($exact)
                    Exact aggregation over every matching span (ClickHouse store).
                @else
                    Sampled from the most recent matching traces — representative, not exact (a ClickHouse store aggregates every span).
                @endif
                Ranked by total DB time consumed; query text is parameterised and redacted at emit time.
            </div>
        @endif
    @endif
</x-telemetry-ui::card>
