@props(['services' => [], 'environments' => [], 'servicesLocked' => false, 'environmentsLocked' => false])

{{-- The selection comes from the shared view state (URL first, then what the
     reader last chose), so the picker always shows the scope the cards are
     querying — never the default while the cards are somewhere else. --}}
@php($state = app(Cbox\TelemetryUi\Support\ViewState::class))
@php($currentService = $state->service())
@php($currentEnv = $state->environment())

{{-- A dimension locked to a single value has no choice to offer, so its picker
     is hidden entirely; the scope is enforced at query time regardless. --}}
@php($showService = ! ($servicesLocked && count($services) <= 1))
@php($showEnv = ! ($environmentsLocked && count($environments) <= 1))

{{-- Sentry-style top-bar scope: service + environment side by side, next to
     the period picker. Changing either reloads with the new scope. --}}
<div class="tui-scope"
     x-data
     x-on:change="
        const url = new URL(window.location);
        // Always SET, even to ''. An empty parameter is how 'All services' is
        // said out loud: deleting it would be indistinguishable from 'not
        // specified', and the remembered scope would come straight back.
        url.searchParams.set($event.target.name, $event.target.value);
        window.location = url;
     ">
    @if ($showService)
        <label class="tui-scope-field">
            <x-telemetry-ui::combobox name="service" aria-label="Service" title="Service">
                @unless ($servicesLocked)
                    <option value="" @selected($currentService === '')>All services</option>
                @endunless
                @foreach ($services as $service)
                    <option value="{{ $service }}" @selected($service === $currentService)>{{ $service }}</option>
                @endforeach
            </x-telemetry-ui::combobox>
        </label>
    @endif

    @if ($showEnv)
        <label class="tui-scope-field">
            <x-telemetry-ui::combobox name="env" aria-label="Environment" title="Environment">
                @unless ($environmentsLocked)
                    <option value="" @selected($currentEnv === '')>All envs</option>
                @endunless
                @foreach ($environments as $environment)
                    <option value="{{ $environment }}" @selected($environment === $currentEnv)>{{ $environment }}</option>
                @endforeach
            </x-telemetry-ui::combobox>
        </label>
    @endif
</div>
