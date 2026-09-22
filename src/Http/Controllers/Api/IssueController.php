<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Http\Controllers\Api;

use Cbox\TelemetryUi\Connectors\ConnectionManager;
use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Contracts\CreatesIssues;
use Cbox\TelemetryUi\Http\Api\ApiError;
use Cbox\TelemetryUi\Http\Api\Json;
use Cbox\TelemetryUi\Http\Api\Serializer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Tracker issues: `GET /api/v2/issues/{id}` reads one, `POST /api/v2/issues`
 * files one (compose-ticket from an error group). Writing needs the separate
 * `manageTelemetryUi` ability — enforced here, never trusted to the client.
 */
final class IssueController
{
    public function show(ConnectionManager $connections, string $id): JsonResponse
    {
        if (! $connections->hasIssues()) {
            return ApiError::notFound('No issue tracker is configured.');
        }

        try {
            return Json::ok(Serializer::issue($connections->issues()->issue($id)));
        } catch (SourceException $exception) {
            return ApiError::backend($exception->getMessage());
        }
    }

    public function store(Request $request, ConnectionManager $connections): JsonResponse
    {
        if (! Gate::allows('manageTelemetryUi')) {
            return ApiError::forbidden('You are not authorized to create issues.');
        }

        $title = trim((string) $request->input('title', ''));

        if ($title === '') {
            return ApiError::invalid('A title is required.');
        }

        $source = $connections->hasIssues() ? $connections->issues() : null;

        if (! $source instanceof CreatesIssues) {
            return ApiError::invalid('The configured tracker cannot create issues.');
        }

        $labels = array_values(array_filter((array) $request->input('labels', []), 'is_string'));

        try {
            $issue = $source->createIssue($title, (string) $request->input('body', ''), $labels);
        } catch (SourceException $exception) {
            return ApiError::backend($exception->getMessage());
        }

        return Json::ok(Serializer::issue($issue), 201);
    }
}
