<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * @param  array<string, list<array{0: array<string, string>, 1: float}>>  $byMetric
 */
function connectionPairBackend(array $byMetric): void
{
    Http::fake([
        'prometheus.test:9090/api/v1/query*' => function (Request $request) use ($byMetric) {
            $query = (string) (requestQuery($request)['query'] ?? '');

            foreach ($byMetric as $suffix => $series) {
                if (str_contains($query, $suffix)) {
                    return Http::response(labelledVector($series));
                }
            }

            return Http::response(labelledVector([]));
        },
    ]);
}

it('finds the one host whose handshake to an upstream is nothing like its peers', function (): void {
    // Averaged across the fleet this is a slow upstream, and there is
    // nothing to do about a slow upstream. Split by the machine that made
    // the call it is a box in the wrong region — which is a ticket.
    connectionPairBackend([
        '_bucket' => [
            [['host_name' => 'web-1', 'server_address' => 'api.test'], 0.004],
            [['host_name' => 'web-2', 'server_address' => 'api.test'], 0.005],
            [['host_name' => 'web-3', 'server_address' => 'api.test'], 0.080],
        ],
        '_sum' => [
            [['host_name' => 'web-1', 'server_address' => 'api.test'], 0.40],
            [['host_name' => 'web-2', 'server_address' => 'api.test'], 0.50],
            [['host_name' => 'web-3', 'server_address' => 'api.test'], 8.00],
        ],
        '_count' => [
            [['host_name' => 'web-1', 'server_address' => 'api.test'], 100],
            [['host_name' => 'web-2', 'server_address' => 'api.test'], 100],
            [['host_name' => 'web-3', 'server_address' => 'api.test'], 100],
        ],
    ]);

    $this->getJson(panelUrl('outgoing-connection-pairs'))
        ->assertOk()
        // Worst first: the reason to open this panel is to find the bad pair.
        ->assertJsonPath('rows.0.from.v', 'web-3')
        ->assertJsonPath('rows.0.to.v', 'api.test')
        ->assertJsonPath('rows.0.p95.tone', 'danger')
        // Against the median of its peers, not against the fleet mean —
        // a mean would be dragged by the outlier being looked for.
        ->assertJsonPath('rows.0.vs.v', '16.0×')
        ->assertJsonPath('rows.1.from.v', 'web-2')
        ->assertJsonPath('rows.1.p95.tone', null);
});

it('does not call a host an outlier when it has no peers to be one against', function (): void {
    // One host reaching one upstream is the whole population. Printing
    // "1×" would imply a comparison that was never made.
    connectionPairBackend([
        '_bucket' => [[['host_name' => 'web-1', 'server_address' => 'api.test'], 0.080]],
        '_sum' => [[['host_name' => 'web-1', 'server_address' => 'api.test'], 8.00]],
        '_count' => [[['host_name' => 'web-1', 'server_address' => 'api.test'], 100]],
    ]);

    $this->getJson(panelUrl('outgoing-connection-pairs'))
        ->assertOk()
        ->assertJsonPath('rows.0.vs.v', '—')
        ->assertJsonPath('rows.0.p95.tone', null);
});

it('says so when nothing opened a connection, rather than showing an empty table', function (): void {
    connectionPairBackend([]);

    $this->getJson(panelUrl('outgoing-connection-pairs'))
        ->assertOk()
        ->assertJsonPath('rows', [])
        ->assertJsonPath('empty', 'No connections were opened in this period. Either everything was pooled, or nothing called out.');
});

it('counts a call that never reached the far end as a connection failure', function (): void {
    // This headline used to query `http_client_connection_failures_total`,
    // which the emitter has never produced — so the number that exists to
    // show a dependency going away read zero through every outage. A call
    // that failed before a response carries `error.type` and no status
    // code, which is both real and more useful: the series says what
    // failed.
    Http::fake([
        'prometheus.test:9090/api/v1/query_range*' => Http::response(['status' => 'success', 'data' => ['resultType' => 'matrix', 'result' => []]]),
        'prometheus.test:9090/api/v1/query*' => function (Request $request) {
            $query = (string) (requestQuery($request)['query'] ?? '');

            return Http::response(labelledVector(match (true) {
                str_contains($query, 'error_type!=') => [[[], 7]],
                str_contains($query, '5..') => [[[], 2]],
                default => [[[], 500]],
            }));
        },
    ]);

    $this->getJson(panelUrl('outgoing-activity'))
        ->assertOk()
        ->assertJsonPath('stats.2.label', 'Conn. failures')
        ->assertJsonPath('stats.2.value', '7')
        ->assertJsonPath('stats.2.tone', 'danger');
});
