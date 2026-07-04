<x-telemetry-ui::card title="Analytics" subtitle="Real visits from the unsampled page-view stream. Unique visitors are the cookieless daily session hash — no cookies, no PII." span="2">
    @if ($error)
        <div class="tui-error">{{ $error }}</div>
    @elseif ($stats === [])
        <div class="tui-empty">No page views in this period. Enable analytics in cboxdk/laravel-telemetry (@telemetryBrowser + TELEMETRY_ANALYTICS) to populate this.</div>
    @else
        <x-telemetry-ui::stats :items="$stats" />
    @endif
</x-telemetry-ui::card>
