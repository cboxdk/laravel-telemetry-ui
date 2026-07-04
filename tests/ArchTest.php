<?php

declare(strict_types=1);

arch('no debug calls ship')->expect(['dd', 'dump', 'ray', 'var_dump', 'print_r', 'die', 'exit'])
    ->not->toBeUsed();

arch('strict types everywhere')->expect('Cbox\TelemetryUi')
    ->toUseStrictTypes();

arch('contracts are interfaces')->expect('Cbox\TelemetryUi\Contracts')
    ->toBeInterfaces();

arch('drivers only depend on contracts and results')->expect('Cbox\TelemetryUi\Connectors')
    ->not->toUse(['Cbox\TelemetryUi\Cards', 'Cbox\TelemetryUi\Http']);

arch('result DTOs are self-contained')->expect('Cbox\TelemetryUi\Queries\Results')
    ->toOnlyUse(['DateTimeImmutable']);

// Boot hygiene: the service provider registers class-string maps and lazy
// singletons only — it must never reach for the HTTP client or a concrete
// backend driver, which would mean instantiating a connector at boot.
arch('the service provider never touches connectors at boot')
    ->expect('Cbox\TelemetryUi\TelemetryUiServiceProvider')
    ->not->toUse([
        'Cbox\TelemetryUi\Connectors\ApiClient',
        'Cbox\TelemetryUi\Connectors\Prometheus',
        'Cbox\TelemetryUi\Connectors\Tempo',
        'Cbox\TelemetryUi\Connectors\Loki',
        'Cbox\TelemetryUi\Connectors\GitHub',
        'Cbox\TelemetryUi\Connectors\Sentry',
        'Cbox\TelemetryUi\Connectors\Linear',
        'Illuminate\Support\Facades\Http',
    ]);
