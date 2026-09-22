<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Http\Controllers\Api;

use Cbox\TelemetryUi\Http\Api\ApiError;
use Cbox\TelemetryUi\Http\Api\Json;
use Cbox\TelemetryUi\TelemetryUiManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * `GET /api/v2/pages/{page}` — a registered page and the panels on it, in
 * order. The SPA fetches each panel on its own, so one slow backend query never
 * blocks the page.
 */
final class PageController
{
    public function __invoke(TelemetryUiManager $manager, string $page): JsonResponse
    {
        $meta = $manager->pages()[$page] ?? null;

        if ($meta === null) {
            return ApiError::notFound("Unknown page [{$page}].");
        }

        if (! Gate::allows('viewTelemetryUi', [$page])) {
            return ApiError::forbidden();
        }

        return Json::ok([
            'page' => $page,
            'label' => $meta['label'],
            'group' => $meta['group'],
            'panels' => array_map(static fn (string $panel): array => [
                'id' => $panel::id(),
                'span' => $panel::span(),
            ], $manager->panels($page)),
        ]);
    }
}
