<x-telemetry-ui::layout :pages="$pages" active="traces" :services="$services" :environments="$environments" :title="'Trace '.substr($traceId, 0, 8)" :commands="$commands" :traceBase="$traceBase" :traceSentinel="$traceSentinel">
    <div class="tui-crumbs">
        <a href="{{ route('telemetry-ui.page', array_filter(['page' => 'traces', 'period' => request('period'), 'service' => request('service'), 'env' => request('env')])) }}">Traces</a>
        <span> / {{ $traceId }}</span>
    </div>

    <header class="tui-header">
        <h1>{{ $trace?->root()?->name ?: 'Trace' }}</h1>
    </header>

    <div class="tui-card tui-span-2">
        <div class="tui-card-body">
            @include('telemetry-ui::partials.trace-detail', ['trace' => $trace, 'rows' => $rows, 'chain' => $chain, 'identities' => $identities, 'error' => $error, 'context' => $context])
        </div>
    </div>
</x-telemetry-ui::layout>
