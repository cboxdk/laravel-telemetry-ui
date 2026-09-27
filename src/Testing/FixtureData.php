<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Testing;

use DateTimeImmutable;
use DateTimeInterface;

/**
 * Deterministic numbers for the fixture backends.
 *
 * Every screen in this package reads three backends over HTTP, which made
 * the dashboard the one part of it that no test ever rendered: the PHP suite
 * fakes the HTTP client, the vitest suite mounts components against
 * hand-written props, and neither one starts the app, boots the SPA and looks
 * at what came out. A browser test can — but only against data, and pointing
 * one at a real Tempo means a test that needs infrastructure, credentials,
 * and a backend whose contents change between runs.
 *
 * So the fixture backends answer from arithmetic. Three properties make that
 * useful rather than a stub:
 *
 * **Deterministic.** Everything is seeded from the query, so a screen renders
 * identically today and in six months. That is what makes a committed
 * screenshot and a visual assertion possible at all.
 *
 * **Shaped by the metric.** A duration comes back as seconds in a plausible
 * band, a ratio between 0 and 1, bytes as bytes. One waveform for everything
 * would render charts that plot and mean nothing — and would hide a unit bug
 * behind numbers that happen to look like numbers.
 *
 * **Complete.** Including the optional contracts, so no panel falls back to
 * an empty state and a screenshot shows a populated screen.
 *
 * It is not a simulation. Nothing connects a trace to the metrics beside it,
 * and none of it should be read as what a real service looks like.
 */
final class FixtureData
{
    /** @var list<string> */
    public const SERVICES = ['checkout', 'catalogue', 'identity', 'billing', 'search'];

    /**
     * Route paths, as a `http.route` label holds them.
     *
     * @var list<string>
     */
    public const PATHS = [
        '/orders/{order}',
        '/checkout',
        '/products',
        '/products/{product}',
        '/cart/items',
        '/healthz',
    ];

    /**
     * Span names, which DO carry the method — `GET /orders/{order}` is what a
     * server span is called, and what a trace list shows.
     *
     * @var list<string>
     */
    public const ROUTES = [
        'GET /orders/{order}',
        'POST /checkout',
        'GET /products',
        'GET /products/{product}',
        'POST /cart/items',
        'GET /healthz',
    ];

    /**
     * The exception classes a Laravel application actually reports.
     *
     * @var list<string>
     */
    public const EXCEPTIONS = [
        'Illuminate\\Database\\QueryException',
        'Illuminate\\Http\\Client\\RequestException',
        'GuzzleHttp\\Exception\\ConnectException',
        'Illuminate\\Validation\\ValidationException',
        'Symfony\\Component\\HttpKernel\\Exception\\NotFoundHttpException',
        'RuntimeException',
    ];

    /**
     * Hosts an application calls out to.
     *
     * @var list<string>
     */
    public const UPSTREAMS = [
        'api.stripe.com',
        'api.upstream.test',
        'hooks.slack.com',
        's3.eu-west-1.amazonaws.com',
        'api.postmarkapp.com',
    ];

    /**
     * Statements a slow-query card plausibly surfaces — the shape of a query
     * that takes 400ms, not a `select 1`.
     *
     * @var list<string>
     */
    public const STATEMENTS = [
        'select * from `orders` inner join `order_lines` on `order_lines`.`order_id` = `orders`.`id` where `orders`.`created_at` >= ? order by `orders`.`created_at` desc',
        'select count(*) as aggregate from `page_views` where `created_at` between ? and ?',
        'update `stock` set `reserved` = `reserved` + ?, `updated_at` = ? where `product_id` = ?',
        'select * from `products` where `products`.`deleted_at` is null and match(`name`, `description`) against (?)',
        'delete from `sessions` where `last_activity` < ?',
    ];

    /**
     * Browser exceptions: type, message, file. Frontend faults come off the
     * span rather than out of a Loki record, and they read differently — a
     * minified chunk, not an app/ path.
     *
     * @var list<array{string, string, string, int}>
     */
    public const BROWSER_EXCEPTIONS = [
        ['TypeError', "Cannot read properties of undefined (reading 'total')", 'assets/checkout-4f1a9c.js', 1284],
        ['TypeError', 'n.addEventListener is not a function', 'assets/app-9b2e71.js', 612],
        ['RangeError', 'Maximum call stack size exceeded', 'assets/cart-2d8f04.js', 88],
        ['ReferenceError', 'dataLayer is not defined', 'assets/analytics-7c3b11.js', 47],
    ];

    public function __construct(
        /**
         * Anchors every timestamp. Null follows the clock, which is what a
         * dashboard wants; pinned, a screenshot taken today matches one taken
         * a year ago.
         */
        private readonly ?DateTimeInterface $now = null,
        private readonly int $seed = 20260927,
    ) {}

    public function clock(): DateTimeInterface
    {
        return $this->now ?? new DateTimeImmutable;
    }

    /**
     * The known values of a label, by what the label evidently means.
     *
     * @return list<string>
     */
    public function labelValues(string $label, string $metric = ''): array
    {
        // `state` means different things to different instruments: a queue's
        // depth is split pending/scheduled/reserved, a worker pool's count is
        // busy/idle. Answering one vocabulary for both left whichever card
        // lost the coin toss with a chart of series it filtered all out — and
        // a legend is the one place a fixture cannot be vague.
        if ($label === 'state' || str_contains($label, '_state') || str_ends_with($label, '.state')) {
            return match (true) {
                str_contains($metric, 'worker') => ['busy', 'idle'],
                str_contains($metric, 'memory') => ['used', 'cached', 'buffered', 'free'],
                str_contains($metric, 'filesystem'), str_contains($metric, 'disk') => ['used', 'free', 'reserved'],
                default => ['pending', 'scheduled', 'reserved'],
            };
        }

        return match (true) {
            // Order matters, and this is where it bit: `geo.country_code`
            // contains `code`, so a facet labelled COUNTRY came back full
            // of HTTP status codes. Specific before general, always.
            // Before the generic arms: a `db.query.text` label holds a
            // statement, and falling through to the placeholder listed the
            // package's own query-performance table as alpha, beta, gamma.
            str_contains($label, 'query.text'), str_contains($label, 'query_text'),
            str_contains($label, 'statement') => self::STATEMENTS,
            // OTel spells a network direction receive/transmit, and the
            // system network chart legend read "alpha / beta / gamma".
            str_contains($label, 'direction') => ['receive', 'transmit'],
            // The load average's three windows, which the card names itself.
            str_contains($label, 'window'), str_contains($label, 'interval') => ['1m', '5m', '15m'],
            str_contains($label, 'exception'), str_contains($label, 'throwable') => self::EXCEPTIONS,
            // The other end of an outgoing call.
            str_contains($label, 'server_address'), str_contains($label, 'server.address'),
            str_contains($label, 'peer'), str_contains($label, 'upstream') => self::UPSTREAMS,
            // A queue connection, not a database one: this is the driver a
            // queue runs on.
            str_contains($label, 'connection') => ['redis', 'database', 'sqs'],
            str_contains($label, 'country') => ['DK', 'SE', 'DE', 'GB', 'US'],
            str_contains($label, 'city') => ['Copenhagen', 'Aarhus', 'Berlin', 'London'],
            str_contains($label, 'user') => ['u_4812', 'u_1193', 'u_7740', 'u_2056'],
            // A caller's address, before the generic `address` arm below —
            // the trace list labelled every row "Client IP beta".
            str_contains($label, 'ip'), str_contains($label, 'client') => ['198.51.100.24', '203.0.113.9', '192.0.2.77', '192.0.2.145'],
            str_contains($label, 'path'), str_contains($label, 'uri') => self::PATHS,
            str_contains($label, 'service') => self::SERVICES,
            // The PATH only: a route label sits beside a method label, and
            // returning "GET /orders/{order}" for it put a PATCH badge next
            // to a route that says GET.
            str_contains($label, 'route'), str_contains($label, 'target') => self::PATHS,
            str_contains($label, 'env'), str_contains($label, 'deployment') => ['production', 'staging'],
            str_contains($label, 'status'), str_contains($label, 'code') => ['200', '201', '404', '422', '500'],
            str_contains($label, 'method') => ['GET', 'POST', 'PATCH', 'DELETE'],
            str_contains($label, 'queue') => ['default', 'emails', 'exports'],
            str_contains($label, 'host'), str_contains($label, 'instance') => ['web-1', 'web-2', 'worker-1'],
            str_contains($label, 'level'), str_contains($label, 'severity') => ['info', 'warning', 'error'],
            default => ['alpha', 'beta', 'gamma'],
        };
    }

    /**
     * The value for a metric at a moment, shaped by what the metric IS.
     *
     * @param  array<string, string>  $labels
     */
    /**
     * @param  array<string, string>  $labels
     * @param  float|null  $quantile  scales the value the way a quantile
     *                                relates to its own distribution: a p95
     *                                is a multiple of the average, not an
     *                                unrelated number. Seeded without it, so
     *                                avg and p95 of the same metric move
     *                                together — otherwise a panel shows
     *                                "avg 19.2µs" beside "p95 550ms" and
     *                                anyone who reads dashboards sees a fake.
     * @param  float  $countScale  seconds of the window a counting query
     *                             covers: 1 for a per-second rate, 3600 for
     *                             `increase(1h)`. Counts are generated as a
     *                             RATE and scaled here, because a card reads
     *                             the same counter both ways — the chart as
     *                             requests per minute, the stat beside it as
     *                             the period total. Returning one number for
     *                             both drew a y-axis peaking at 140k/min next
     *                             to a total of 1.66K.
     */
    public function value(
        string $metric,
        array $labels,
        int $at,
        bool $counting = false,
        ?float $quantile = null,
        float $countScale = 1.0,
    ): float {
        $name = strtolower($metric);

        // Seeded on the FAMILY, not the exact series: `_sum`, `_count` and
        // `_bucket` are three reads of one histogram, and seeding each on
        // its own name gave them three unrelated waves. The panel divides
        // one by another, so they have to move together — otherwise p95
        // lands at a hundred times the average and the picture is a lie
        // that happens to plot.
        $family = (string) preg_replace('/[._](sum|count|bucket|total)$/', '', $name);

        // The PHASE comes from the family alone, and the labels only vary the
        // amplitude around it. Seeding the wave itself on the labels gave
        // every status class, route and queue its own independent phase, with
        // two consequences: the stacked request chart was noise rather than a
        // traffic curve, and an ungrouped `sum()` — which is one series with
        // no labels, and so its own unrelated phase — bore no relation to the
        // sum of the parts it aggregates. The throughput delta compares
        // exactly those two, and read "▲ 5,696%".
        $phase = $this->int($family);
        $seed = $this->int($family.json_encode($labels));

        // A slow daily swell plus a small fast ripple. One fast sine over
        // an hour's range swept a whole period and drew a decaying curve,
        // so every chart looked like an incident in progress rather than a
        // service behaving normally.
        $swell = 0.5 + 0.5 * sin(($at / 21_600.0) + ($phase % 1000) / 159.0);
        $ripple = 0.5 + 0.5 * sin(($at / 900.0) + ($phase % 97));
        $wave = 0.65 * $swell + 0.35 * $ripple;
        $jitter = 0.85 + 0.3 * (($this->int($seed.':'.intdiv($at, 15)) % 1000) / 1000.0);

        // Series of one family rise and fall together, a little apart.
        $spread = 0.8 + 0.4 * (($seed % 1000) / 1000.0);

        // p50 ≈ 1.3x, p95 ≈ 4.7x, p99 ≈ 5.3x the average — the shape of a
        // real latency distribution rather than three independent numbers.
        $tail = $quantile === null ? 1.0 : 1.0 + 4.5 * ($quantile ** 4);

        // `$spread` here too, so routes have their own latencies: without it
        // every row of the routes table reported the same p95 to the
        // millisecond, which no real service has ever done.
        $duration = round((0.008 + 0.09 * $wave * $wave) * $jitter * $spread * $tail, 6);
        // Per second, in a band narrow enough that an hour of it reads as a
        // service with a daily rhythm rather than one in the middle of an
        // incident: a wide band made the hour-over-hour delta "▲ 297%".
        $rate = (2.5 + 7.0 * $wave * $jitter) * $spread * $this->weight($name, $labels);
        $count = round($rate * $countScale, 3);

        return match (true) {
            // A histogram's `_sum` is TOTAL time, not one duration: a
            // panel reads average latency as sum/count, and giving those
            // two independent values made the average 38µs beside a p95
            // of 400ms. They have to come from the same distribution or
            // the arithmetic downstream is nonsense.
            str_ends_with($name, '_sum'), str_ends_with($name, '.sum') => round($duration * $count, 4),

            // A COUNT of something, whatever the something is called. The
            // query decides this, not the metric name: `increase(
            // http_server_request_duration_count[1h])` is a number of
            // requests, and shaping it from the word "duration" returned
            // 0.31 — which the dashboard rendered, correctly and
            // uselessly, as "Requests: 1".
            // A byte counter's rate is a THROUGHPUT: read as a plain count it
            // gave the network chart an axis in single bytes per second.
            $counting && (str_contains($name, 'bytes') || str_contains($name, 'octets')) => round($count * 2_000_000),

            $counting => $count,

            // A metric that says `_percent` is already scaled 0-100, and the
            // card divides by a hundred to format it. Shaped as a ratio it
            // rendered a fleet utilization of 0.44% beside more busy workers
            // than idle ones; shaped generically, a queue failure rate of
            // 385%.
            str_ends_with($name, '_percent'), str_contains($name, '_percent_'),
            str_ends_with($name, '.percent') => str_contains($name, 'failure') || str_contains($name, 'error')
                ? round(0.4 + 7.0 * $wave * $jitter * $spread, 2)
                : round(20.0 + 65.0 * $wave * $jitter * $spread, 2),

            // Workers are a small fleet of processes, not a counter: reading
            // the `_count` suffix as "a counter, cumulative over an hour" put
            // 23.1K busy workers on a page about three queues.
            str_contains($name, 'worker') => round(2 + 30 * $wave * $jitter * $spread),

            // A load average is runnable processes, compared against the core
            // count — single digits. The generic arm made it 500.
            str_contains($name, 'load_average'), str_contains($name, 'load.average'),
            str_contains($name, 'loadavg') => round(0.2 + 6.0 * $wave * $jitter * $spread, 2),

            // SECONDS, because laravel-telemetry 3.0 emits seconds. A fixture
            // still speaking milliseconds here would quietly validate a
            // dashboard that is wrong, which is the one thing it must not do.
            //
            // Ahead of the ratio arm, and the ratio arm now matches a SUFFIX,
            // because "du-ratio-n" contains "ratio": every duration metric in
            // the package fell into it and came back as a number between 0.05
            // and 0.99 that depended on neither the labels nor the quantile.
            // The routes table reported one identical p95 for every route, and
            // read as a table of real latencies while being a single number
            // printed eight times.
            str_contains($name, 'duration'), str_contains($name, 'latency'),
            str_contains($name, '.time') => $duration,

            // A ratio is named as one — semconv spells it as a `.ratio`
            // suffix — rather than merely containing the letters.
            str_ends_with($name, '_ratio'), str_ends_with($name, '.ratio'),
            str_contains($name, 'utilization'),
            str_contains($name, 'saturation') => round(min(0.99, 0.05 + 0.5 * $wave * $spread), 4),

            // Gigabytes: these are a host's memory and its disks, and a
            // machine with 700MB of filesystem is not one anybody runs.
            str_contains($name, 'bytes'), str_contains($name, 'memory'),
            str_contains($name, 'usage') => round((2.0 + 14.0 * $wave) * 1_073_741_824 * $jitter * $spread),

            str_contains($name, 'error'), str_contains($name, 'failed'),
            str_contains($name, 'dropped') => round(max(0.0, 12 * $wave * $jitter - 4), 3),

            str_contains($name, 'depth'), str_contains($name, 'lag'),
            str_contains($name, 'pending') => round(40 * $wave * $jitter),

            default => round((30 + 900 * $wave) * $jitter, 3),
        };
    }

    /**
     * A plausible value for a span or resource attribute, by what the
     * attribute's NAME says it holds. Stable for the pair, so the same span
     * carries the same values on every read.
     */
    public function attribute(string $key, string $of): string
    {
        $name = strtolower($key);
        $seed = "attr:{$name}:{$of}";

        // One fault per span, not one per attribute. Drawing the type, the
        // message, the file and the line independently produced spans that
        // said "RangeError: dataLayer is not defined" at a line no file has —
        // and, because the errors screen fingerprints a frontend fault from
        // its type, file and line, gave almost every span its own group. The
        // grouped table listed twenty-seven groups of one event each, which
        // is the opposite of what it exists to show.
        $fault = $this->one("fault:{$of}", self::BROWSER_EXCEPTIONS);

        return match (true) {
            $name === 'db.query.text' => $this->one($seed, self::STATEMENTS),
            $name === 'db.system.name', $name === 'db.system' => $this->one($seed, ['mysql', 'mysql', 'pgsql', 'sqlite']),
            $name === 'db.namespace' => $this->one($seed, ['shop', 'shop', 'analytics']),
            $name === 'exception.type' => $fault[0],
            $name === 'exception.message' => $fault[1],
            $name === 'exception.file' => $fault[2],
            $name === 'exception.line' => (string) $fault[3],
            $name === 'url.full' => 'https://'.$this->one($seed, ['api.upstream.test', 'rates.upstream.test', 'cdn.example.test']).'/v1/rates',
            $name === 'browser' => 'true',
            // Weighted, unlike the facet's key list: an attribute drawn
            // uniformly from 200/201/404/422/500 made two spans in five an
            // error, and the explorer reported a 32% error rate.
            str_contains($name, 'status_code'), str_contains($name, 'status.code') => $this->one($seed, [
                '200', '200', '200', '200', '200', '200', '200', '200', '200', '200',
                '200', '201', '201', '201', '204', '301', '404', '404', '422', '500',
            ]),
            str_contains($name, 'duration'), str_contains($name, 'latency') => (string) round($this->band($seed, 0.004, 1.9), 6),
            default => (string) $this->one($seed, $this->labelValues($name)),
        };
    }

    /**
     * How common the thing being counted actually is.
     *
     * Every series of a family came back the same size, so the request chart
     * put 2.54K server errors beside 7.78K successes — a 16% error rate on the
     * front page of the documentation — and the jobs card reported more failed
     * jobs than processed ones. A fixture may not know what a real service
     * looks like, but it must not draw one that is on fire.
     *
     * Public because the traces fixture weighs its span buckets the same
     * way: a facet listing 3.4k 500s beside 3.4k 200s is the same lie told
     * about spans instead of samples.
     *
     * @param  array<string, string>  $labels
     */
    public function weight(string $name, array $labels): float
    {
        $weight = match (true) {
            str_contains($name, 'failed'), str_contains($name, 'exception'),
            str_contains($name, 'dropped'), str_contains($name, 'error') => 0.012,
            str_contains($name, 'released'), str_contains($name, 'retried'),
            str_contains($name, 'timeout') => 0.05,
            // A third of all queries rolling back is not a database, it is a
            // disaster; a rollback is rare and a duplicate is uncommon.
            str_contains($name, 'rolled_back'), str_contains($name, 'rollback') => 0.004,
            str_contains($name, 'duplicat') => 0.02,
            // A pooled client opens far fewer connections than it makes
            // requests — that is what pooling IS. Counting them alike put
            // more connections than requests against every upstream and made
            // the table's "Reused" column read 0% for all of them.
            str_contains($name, 'connection') => 0.15,
            default => 1.0,
        };

        foreach ($labels as $value) {
            $weight *= match (true) {
                // An HTTP status code, whatever the label is called — and
                // however a selector spells a class of them (`5..`, `5xx`).
                preg_match('/^[1-5][\dx.]{2}$/i', $value) === 1 => match ($value[0]) {
                    '2' => $value === '200' ? 1.0 : 0.2,
                    '3' => 0.06,
                    '4' => $value === '404' ? 0.03 : 0.012,
                    default => 0.004,
                },
                // Read-heavy, as web traffic is: the span explorer's METHOD
                // facet listed GET below DELETE.
                $value === 'GET' => 1.0,
                $value === 'POST' => 0.3,
                $value === 'PATCH', $value === 'PUT' => 0.08,
                $value === 'DELETE' => 0.04,
                $value === 'failed', $value === 'error', $value === 'fatal' => 0.012,
                $value === 'released', $value === 'retried', $value === 'warning' => 0.05,
                $value === 'reserved' => 0.25,
                $value === 'scheduled' => 0.4,
                default => 1.0,
            };
        }

        return $weight;
    }

    /**
     * A value in [$min, $max], stable for the key.
     */
    public function band(string $key, float $min, float $max): float
    {
        return $min + ($max - $min) * (($this->int($key) % 10_000) / 10_000.0);
    }

    /**
     * @template T
     *
     * @param  list<T>  $values
     * @return T
     */
    public function one(string $key, array $values): mixed
    {
        return $values[$this->int($key) % count($values)];
    }

    public function id(string $of, int $length = 32): string
    {
        return substr(hash('xxh128', $this->seed.':'.$of), 0, $length);
    }

    public function int(string $of): int
    {
        return (int) hexdec(substr(hash('xxh128', $this->seed.':'.$of), 0, 8));
    }
}
