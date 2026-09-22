<?php

declare(strict_types=1);

use Cbox\Telemetry\TelemetryManager;

it('asks laravel-telemetry to ignore the dashboard path when the manager resolves', function () {
    $fake = new class
    {
        /** @var list<string> */
        public array $ignored = [];

        /** @param  string|list<string>  $patterns */
        public function ignorePaths(string|array $patterns): void
        {
            $this->ignored = [...$this->ignored, ...(array) $patterns];
        }
    };

    app()->bind(TelemetryManager::class, fn () => $fake);
    app(TelemetryManager::class);

    expect($fake->ignored)->toBe(['telemetry-ui', 'telemetry-ui/*']);
});

it('tolerates laravel-telemetry releases without ignorePaths()', function () {
    app()->bind(TelemetryManager::class, fn () => new stdClass);

    expect(app(TelemetryManager::class))->toBeInstanceOf(stdClass::class);
});
