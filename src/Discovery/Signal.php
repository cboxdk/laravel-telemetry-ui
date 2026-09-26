<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Discovery;

/**
 * One thing an exporter can tell you about a target, and how to ask for it.
 *
 * `$expression` is PromQL with a `{selector}` token that discovery fills in
 * with the label matcher that pins this exporter instance — so the same
 * definition works whether the instance is `10.0.0.4:9100` or `web-3:9100`.
 */
final readonly class Signal
{
    public function __construct(
        public string $key,
        public string $label,
        public string $expression,
        public string $unit = '',
        /**
         * Only present in some deployments (a replica, a cgroup, a kernel
         * with PSI). Absence is normal and is not reported as a problem.
         */
        public bool $conditional = false,
    ) {}

    public function promql(string $selector): string
    {
        return str_replace('{selector}', $selector, $this->expression);
    }
}
