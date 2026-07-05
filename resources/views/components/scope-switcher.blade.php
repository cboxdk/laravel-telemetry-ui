@props(['services' => [], 'environments' => []])

@php($currentService = (string) request('service'))
@php($currentEnv = (string) request('env'))

<div class="tui-scope"
     x-data
     x-on:change="
        const url = new URL(window.location);
        const value = $event.target.value;
        if (value === '') { url.searchParams.delete($event.target.name); }
        else { url.searchParams.set($event.target.name, value); }
        window.location = url;
     ">
    <div class="tui-brand">
        @if ($logo = config('telemetry-ui.brand.logo'))
            <img class="tui-brand-logo" src="{{ $logo }}" alt="">
        @endif
        <span class="tui-brand-name">{{ config('telemetry-ui.brand.name') ?: config('app.name') }}</span>
    </div>

    <label class="tui-scope-field">
        <span>Service</span>
        <select name="service">
            <option value="">All services</option>
            @foreach ($services as $service)
                <option value="{{ $service }}" @selected($service === $currentService)>{{ $service }}</option>
            @endforeach
        </select>
    </label>

    <label class="tui-scope-field">
        <span>Environment</span>
        <select name="env">
            <option value="">All environments</option>
            @foreach ($environments as $environment)
                <option value="{{ $environment }}" @selected($environment === $currentEnv)>{{ $environment }}</option>
            @endforeach
        </select>
    </label>
</div>
