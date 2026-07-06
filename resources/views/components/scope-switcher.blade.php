@props(['services' => [], 'environments' => []])

@php($currentService = (string) request('service'))
@php($currentEnv = (string) request('env'))

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
    <label class="tui-scope-field">
        <select name="service" aria-label="Service" title="Service">
            <option value="">All services</option>
            @foreach ($services as $service)
                <option value="{{ $service }}" @selected($service === $currentService)>{{ $service }}</option>
            @endforeach
        </select>
    </label>

    <label class="tui-scope-field">
        <select name="env" aria-label="Environment" title="Environment">
            <option value="">All envs</option>
            @foreach ($environments as $environment)
                <option value="{{ $environment }}" @selected($environment === $currentEnv)>{{ $environment }}</option>
            @endforeach
        </select>
    </label>
</div>
