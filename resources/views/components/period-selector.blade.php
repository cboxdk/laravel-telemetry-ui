@php($current = Cbox\TelemetryUi\Support\Period::tryFrom((string) request('period')) ?? Cbox\TelemetryUi\Support\Period::default())

<div class="tui-periods" role="tablist" aria-label="Time period">
    @foreach (Cbox\TelemetryUi\Support\Period::cases() as $period)
        <button
            type="button"
            class="tui-period {{ $period === $current ? 'is-active' : '' }}"
            x-data
            x-on:click="
                const url = new URL(window.location);
                url.searchParams.set('period', '{{ $period->value }}');
                window.history.replaceState({}, '', url);
                document.querySelectorAll('.tui-period').forEach(el => el.classList.remove('is-active'));
                $el.classList.add('is-active');
                Livewire.dispatch('telemetry-ui:period-changed', { period: '{{ $period->value }}' });
            "
        >{{ $period->label() }}</button>
    @endforeach
</div>
