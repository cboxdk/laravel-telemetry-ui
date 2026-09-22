<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Panels\Attributes;

use Attribute;
use Cbox\TelemetryUi\Panels\Panel;

/**
 * Binds a public string property of a {@see Panel} to
 * a request query parameter — the entity key of a detail panel (`?route=`) or a
 * panel control (`?min_ms=`). The v2 replacement for Livewire's `#[Url]`.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final readonly class Param
{
    public function __construct(public string $as) {}
}
