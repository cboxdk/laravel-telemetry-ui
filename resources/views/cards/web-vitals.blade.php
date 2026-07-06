<x-telemetry-ui::card title="Core Web Vitals" subtitle="Real-user p75 LCP / CLS / INP per page, reported at page-hide (field data, not lab)" span="2">
    @if ($error)
        <div class="tui-error">{{ $error }}</div>
    @elseif ($rows === [])
        <div class="tui-empty">
            No web-vitals spans in this period. Requires the browser SDK with
            <code>ingest.spans.browser.vitals</code> enabled (default on).
        </div>
    @else
        <x-telemetry-ui::stats :stats="$stats" />

        <div class="tui-table-wrap">
            <table class="tui-table">
                <thead>
                    <tr>
                        <th>Page</th>
                        <th class="is-num">Views</th>
                        <th class="is-num">LCP p75</th>
                        <th class="is-num">CLS p75</th>
                        <th class="is-num">INP p75</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rows as $row)
                        <tr>
                            <td class="is-primary is-wide">{{ $row['path'] }}</td>
                            <td class="is-num">{{ $row['views'] }}</td>
                            <td class="is-num tui-tone-{{ $this->tone($row['lcp'], 2500, 4000) }}">{{ $this->fmt($row['lcp'], 'ms') }}</td>
                            <td class="is-num tui-tone-{{ $this->tone($row['cls'], 0.1, 0.25) }}">{{ $this->fmt($row['cls'], '') }}</td>
                            <td class="is-num tui-tone-{{ $this->tone($row['inp'], 200, 500) }}">{{ $this->fmt($row['inp'], 'ms') }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="tui-note">Green / amber / red on Google's good / needs-improvement / poor thresholds. Bounded trace sample.</div>
    @endif
</x-telemetry-ui::card>
