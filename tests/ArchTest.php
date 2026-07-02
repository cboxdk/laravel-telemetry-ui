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
