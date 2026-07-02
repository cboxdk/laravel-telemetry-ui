---
title: Custom cards
description: Ship dashboard pages and cards from your own packages
weight: 1
---

# Custom cards

Any package (or the app itself) can contribute pages and cards. A card is a
Livewire component: PHP + Blade, no JavaScript build, using whatever
PromQL/TraceQL/LogQL fits — including metrics and spans the core UI knows
nothing about.

```php
// e.g. in cboxdk/queue-autoscale's service provider
use Cbox\TelemetryUi\Facades\TelemetryUi;

public function boot(): void
{
    if (class_exists(TelemetryUi::class)) {
        TelemetryUi::page('autoscale', 'Autoscale', group: 'Activity');
        TelemetryUi::card(\Cbox\QueueAutoscale\Ui\ScalingDecisions::class, page: 'autoscale');
        TelemetryUi::card(\Cbox\QueueAutoscale\Ui\WorkerFleet::class, page: 'autoscale');
    }
}
```

```php
use Cbox\TelemetryUi\Cards\Card;

final class ScalingDecisions extends Card
{
    public function render(): View
    {
        [$start, $end] = $this->range();

        return view('queue-autoscale::cards.scaling-decisions', [
            'series' => $this->toChartSeries($this->metrics()->queryRange(
                'sum by (queue) (rate(autoscale_scaling_events_total[5m])) * 60',
                $start, $end,
            ), label: 'queue'),
        ]);
    }
}
```

```blade
<x-telemetry-ui::card title="Scaling decisions" span="2">
    <x-telemetry-ui::chart :series="$series" type="bar" unit="events/min" />
</x-telemetry-ui::card>
```

## Interactive cards

Cards are ordinary Livewire components, so actions and forms just work:

```php
final class ExceptionActions extends Card
{
    public function createTicket(string $exceptionClass): void
    {
        // call Linear/GitHub, dispatch a job, open a modal…
    }
}
```

Long-running/AI output can stream with `wire:stream`. This is the intended
home for ticket-creation and AI-resolve flows on the roadmap.

## Conventions

- Query through `$this->metrics()` / `$this->traces()` / `$this->logs()` so
  named connections and tenancy keep working.
- Catch `SourceException` and render an inline error state; a broken backend
  must not take the page down.
- Respect `$this->range()`; don't hardcode time windows.
