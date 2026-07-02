# Laravel Telemetry UI

**Grafana-replacement observability UI for Laravel** — queries your existing
Tempo (traces), Loki (logs) and Prometheus/Mimir (metrics) directly. No
agent, no vendor cloud. The presentation counterpart to
[`cboxdk/laravel-telemetry`](https://github.com/cboxdk/laravel-telemetry),
schema-aware of every metric and span attribute it emits.

- **Laravel-shaped screens**: Requests, Jobs, Queries, Exceptions, Users —
  cross-linked from metric aggregate to individual trace waterfall.
- **Extensible via Livewire cards**: your packages add pages with PHP +
  Blade and any PromQL/TraceQL/LogQL — no JS build.
- **Inert when idle**: boot registers class-string maps only; disable with
  one env var.

```bash
composer require cboxdk/laravel-telemetry-ui
```

```dotenv
TELEMETRY_UI_METRICS_URL=http://prometheus:9090
TELEMETRY_UI_TEMPO_URL=http://tempo:3200
TELEMETRY_UI_LOKI_URL=http://loki:3100
```

Then visit `/telemetry-ui` (gated by `viewTelemetryUi`, local-only by
default).

## Documentation

Full documentation lives in [`docs/`](docs/index.md) — installation,
connections, the card/page model, design direction and ADRs.

## Development

```bash
composer check   # pint + phpstan (level 8) + pest
npm run build    # rebuild the ECharts bundle in public/
```

## License

MIT — see [LICENSE.md](LICENSE.md).
