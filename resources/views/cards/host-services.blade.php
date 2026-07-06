<x-telemetry-ui::card title="Services on this host" subtitle="From the services' own Prometheus exporters (mysqld_exporter, redis_exporter, …)" span="2">
    @if ($error)
        <div class="tui-error">{{ $error }}</div>
    @elseif ($services === [])
        <div class="tui-empty">
            No service exporters detected for this host. Point mysqld_exporter /
            redis_exporter / postgres_exporter at the same Prometheus, or add your
            own probes under <code>telemetry-ui.host-services</code>.
        </div>
    @else
        <div class="tui-host-services">
            @foreach ($services as $service)
                <div class="tui-host-service">
                    <div class="tui-host-service-head">
                        <span class="tui-badge {{ $service['up'] ? 'tui-badge-ok' : 'tui-badge-danger' }}">{{ $service['up'] ? 'up' : 'down' }}</span>
                        <strong>{{ $service['label'] }}</strong>
                    </div>
                    <div class="tui-stats">
                        @foreach ($service['tiles'] as $tile)
                            <div class="tui-stat">
                                <span class="tui-stat-label">{{ $tile['label'] }}</span>
                                <span class="tui-stat-value tui-tone-dim">{{ $tile['value'] }}</span>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</x-telemetry-ui::card>
