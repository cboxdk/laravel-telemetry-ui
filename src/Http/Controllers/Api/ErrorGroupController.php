<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Http\Controllers\Api;

use Cbox\TelemetryUi\Analysis\ErrorGroupReport;
use Cbox\TelemetryUi\Analysis\Snapshot\ExceptionSnapshot;
use Cbox\TelemetryUi\Connectors\ConnectionManager;
use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Contracts\CreatesIssues;
use Cbox\TelemetryUi\Http\Api\ApiError;
use Cbox\TelemetryUi\Http\Api\Json;
use Cbox\TelemetryUi\Http\Api\Markdown;
use Cbox\TelemetryUi\Http\Api\RequestScope;
use Cbox\TelemetryUi\Queries\Ir\TraceCondition;
use Cbox\TelemetryUi\Queries\Ir\TraceOp;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * `GET /api/v2/errors/{group}` — a Sentry-style error group: occurrences,
 * stats, the representative exception + request, the suspect deploy and the
 * release spread, all from the shared {@see ErrorGroupReport}. Scoped by the
 * request's service/env selection and the tenancy lock.
 *
 * @phpstan-import-type Report from ErrorGroupReport
 */
final class ErrorGroupController
{
    public function __invoke(Request $request, ErrorGroupReport $reports, ConnectionManager $connections, string $group): JsonResponse
    {
        $report = self::report($request, $reports, $group);

        if ($report instanceof JsonResponse) {
            return $report;
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

    /**
     * `GET /api/v2/errors/{group}/markdown` — the group as a bounded Markdown
     * snapshot ({@see ExceptionSnapshot}), read under the same gate and scope
     * as the group itself. `?format=text` answers with the Markdown itself.
     */
    public function markdown(Request $request, ErrorGroupReport $reports, string $group): JsonResponse|Response
    {
        $report = self::report($request, $reports, $group);

        if ($report instanceof JsonResponse) {
            return $report;
        }

        return Markdown::respond($request, ExceptionSnapshot::build($group, $report), ['group' => $group]);
    }

    /**
     * The group's report, or the answer to give instead: forbidden, invalid
     * or the backend's failure. Scoped by the request's service/env selection
     * and the tenancy lock.
     *
     * @return Report|JsonResponse
     */
    private static function report(Request $request, ErrorGroupReport $reports, string $group): array|JsonResponse
    {
        if (! Gate::allows('viewTelemetryUi', ['exceptions'])) {
            return ApiError::forbidden();
        }

        if (! ErrorGroupReport::validId($group)) {
            return ApiError::invalid('Not a valid error-group id.');
        }

        $scope = RequestScope::fromRequest($request);

        try {
            return $reports->for(
                $group,
                $scope->logQuery(),
                $scope->scopedTraceQuery(
                    TraceCondition::token('span.browser', TraceOp::Eq, 'true'),
                    TraceCondition::nil('span.exception.type'),
                ),
            );
        } catch (SourceException $exception) {
            return ApiError::fromSource($exception);
        }
    }
}
