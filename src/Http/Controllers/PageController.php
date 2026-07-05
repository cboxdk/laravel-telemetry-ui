<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Http\Controllers;

use Cbox\TelemetryUi\Support\Fleet;
use Cbox\TelemetryUi\Support\SchemaDetector;
use Cbox\TelemetryUi\TelemetryUiManager;
use Illuminate\Contracts\View\View;

final class PageController
{
    use BuildsChrome;

    public function __invoke(
        TelemetryUiManager $manager,
        SchemaDetector $detector,
        Fleet $fleet,
        string $page = 'dashboard',
    ): View {
        $pages = $this->accessiblePages($manager, $detector);

        abort_unless(isset($pages[$page]), 404);

        $services = $fleet->services();
        $environments = $fleet->environments();

        /** @var view-string $view */
        $view = 'telemetry-ui::page';

        return view($view, [
            'page' => $page,
            'pages' => $pages,
            'cards' => $manager->cards($page),
            'services' => $services,
            'environments' => $environments,
            ...$this->chrome($pages, $services, $environments, $page),
        ]);
    }
}
