<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

it('charts the average from separate sum and count queries, divided per point', function (): void {
    $rangeQueries = [];

    Http::fake(function (Request $request) use (&$rangeQueries) {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
        $promql = (string) ($query['query'] ?? $request->data()['query'] ?? '');

        if (str_contains($request->url(), 'query_range')) {
            $rangeQueries[] = $promql;
            $value = str_contains($promql, '_sum') ? '0.5' : (str_contains($promql, '_count') ? '2' : '0.3');

            return Http::response(['status' => 'success', 'data' => ['resultType' => 'matrix', 'result' => [
                ['metric' => [], 'values' => [[1735689600, $value], [1735689660, $value]]],
            ]]]);
        }

        return Http::response(['status' => 'success', 'data' => ['resultType' => 'vector', 'result' => [
            ['metric' => [], 'value' => [1735689600, '10']],
        ]]]);
    });

    $response = $this->getJson(panelUrl('request-duration'))->assertOk();

    // 0.5 s of request time / 2 requests per second = 250 ms.
    expect($response->json('series.0.name'))->toBe('AVG')
        ->and($response->json('series.0.data.0.1'))->toEqual(250.0)
        ->and(collect($rangeQueries)->contains(fn (string $q): bool => str_contains($q, '_sum') && str_contains($q, '_count')))->toBeFalse();
});
