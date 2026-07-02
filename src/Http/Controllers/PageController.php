<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Http\Controllers;

use Cbox\TelemetryUi\Support\SchemaDetector;
use Cbox\TelemetryUi\TelemetryUiManager;
use Illuminate\Contracts\View\View;

final class PageController
{
    public function __invoke(TelemetryUiManager $manager, SchemaDetector $detector, string $page = 'dashboard'): View
    {
        $pages = $manager->visiblePages($detector);

        abort_unless(isset($pages[$page]), 404);

        /** @var view-string $view */
        $view = 'telemetry-ui::page';

        return view($view, [
            'page' => $page,
            'pages' => $pages,
            'cards' => $manager->cards($page),
        ]);
    }
}
