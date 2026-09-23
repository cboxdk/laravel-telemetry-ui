<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Http\Controllers\Api;

use Cbox\TelemetryUi\Analysis\ErrorGroupReport;
use Cbox\TelemetryUi\Connectors\ConnectionManager;
use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Contracts\CreatesIssues;
use Cbox\TelemetryUi\Http\Api\ApiError;
use Cbox\TelemetryUi\Http\Api\Json;
use Cbox\TelemetryUi\Http\Api\RequestScope;
use Cbox\TelemetryUi\Queries\Ir\TraceCondition;
use Cbox\TelemetryUi\Queries\Ir\TraceOp;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * `GET /api/v2/errors/{group}` — a Sentry-style error group: occurrences,
 * stats, the representative exception + request, the suspect deploy and the
 * release spread, all from the shared {@see ErrorGroupReport}. Scoped by the
 * request's service/env selection and the tenancy lock.
 */
final class ErrorGroupController
{
    public function __invoke(Request $request, ErrorGroupReport $reports, ConnectionManager $connections, string $group): JsonResponse
    {
        if (! Gate::allows('viewTelemetryUi', ['exceptions'])) {
            return ApiError::forbidden();
        }

        if (! ErrorGroupReport::validId($group)) {
            return ApiError::invalid('Not a valid error-group id.');
        }

        $scope = RequestScope::fromRequest($request);

        try {
            $report = $reports->for(
                $group,
                $scope->logQuery(),
                $scope->scopedTraceQuery(
                    TraceCondition::token('span.browser', TraceOp::Eq, 'true'),
                    TraceCondition::nil('span.exception.type'),
                ),
            );
        } catch (SourceException $exception) {
            return ApiError::backend($exception->getMessage());
        }

        $canCreate = Gate::allows('manageTelemetryUi')
            && $connections->hasIssues()
            && $connections->issues() instanceof CreatesIssues;

        return Json::ok([
            'group' => $group,
            'stats' => $report['stats'],
            'occurrences' => $report['occurrences'],
            'detail' => $report['detail'],
            'request' => $report['request'],
            'suspect' => $report['suspect'],
            'releases' => $report['releases'],
            'lookbackDays' => ErrorGroupReport::LOOKBACK_DAYS,
            'canCreateIssue' => $canCreate,
            'tracker' => $connections->hasIssues() ? $connections->issues()->label() : null,
            'draft' => $canCreate ? $reports->draft($group, $report['stats'], $report['detail']) : null,
            'llm' => $reports->llmMarkdown($group, $report),
        ]);
    }
}
