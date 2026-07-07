<x-telemetry-ui::card title="Performance" subtitle="Real-user Core Web Vitals (p75) and navigation timings for this page — field data, not lab." span="2">
    @if ($error)
        <div class="tui-error">{{ $error }}</div>
    @elseif ($vitals === [] && $timings === [])
        <div class="tui-empty">No browser performance data for this page in this period. Requires the frontend SDK (@telemetryBrowser).</div>
    @else
        @if ($vitals !== [])
            <x-telemetry-ui::stats :items="$vitals" />
        @endif
        @if ($timings !== [])
            <x-telemetry-ui::stats :items="$timings" />
        @endif
        <div class="tui-note">Vitals green / amber / red on Google's good / needs-improvement / poor thresholds. Bounded trace sample.</div>
    @endif
</x-telemetry-ui::card>
