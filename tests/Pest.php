<?php

declare(strict_types=1);

use Cbox\TelemetryUi\Tests\DisabledTestCase;
use Cbox\TelemetryUi\Tests\TestCase;
use Illuminate\Http\Client\Request;

pest()->extend(TestCase::class)->in('Feature');
pest()->extend(DisabledTestCase::class)->in('Disabled');

/**
 * Query-string parameters of a faked client request (GET data lives in the
 * URL, not in Request::data()).
 *
 * @return array<string, mixed>
 */
function requestQuery(Request $request): array
{
    parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

    /** @var array<string, mixed> $query */
    return $query;
}
