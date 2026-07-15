@props(['services' => [], 'environments' => [], 'servicesLocked' => false, 'environmentsLocked' => false])

@php($currentService = (string) request('service'))
@php($currentEnv = (string) request('env'))

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
        const value = $event.target.value;
        if (value === '') { url.searchParams.delete($event.target.name); }
        else { url.searchParams.set($event.target.name, value); }
        window.location = url;
     ">
    @if ($showService)
        <label class="tui-scope-field">
            <x-telemetry-ui::combobox name="service" aria-label="Service" title="Service">
                @unless ($servicesLocked)
                    <option value="">All services</option>
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
                    <option value="">All envs</option>
                @endunless
                @foreach ($environments as $environment)
                    <option value="{{ $environment }}" @selected($environment === $currentEnv)>{{ $environment }}</option>
                @endforeach
            </x-telemetry-ui::combobox>
        </label>
    @endif
</div>
