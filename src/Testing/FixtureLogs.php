<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Testing;

use Cbox\TelemetryUi\Connectors\ProbeResult;
use Cbox\TelemetryUi\Contracts\LogsSource;
use Cbox\TelemetryUi\Contracts\ProbesConnection;
use Cbox\TelemetryUi\Queries\Ir\LabelFilter;
use Cbox\TelemetryUi\Queries\Ir\LineFilter;
use Cbox\TelemetryUi\Queries\Ir\LineOp;
use Cbox\TelemetryUi\Queries\Ir\LogQuery;
use Cbox\TelemetryUi\Queries\Ir\MatchOp;
use Cbox\TelemetryUi\Queries\Results\LogEntry;
use Cbox\TelemetryUi\Support\Analytics;
use DateTimeInterface;

/**
 * A logs backend that answers from arithmetic. See {@see FixtureData}.
 *
 * The line filters are not decoration here, they are the dispatch. Several
 * screens read *events* out of the log stream rather than log lines —
 * analytics is one `analytics.page_view` record per view, deploy markers are
 * `app.deployment` events — and they select them with a line filter naming
 * the event. A fixture that ignored the pipeline and returned prose for
 * everything answered those queries with lines that could never match, so
 * the Analytics page rendered its empty state and the charts drew no deploy
 * markers: four panels quietly blank against a backend that reported no
 * error, which is the worst shape a fixture can fail in.
 */
final class FixtureLogs implements LogsSource, ProbesConnection
{
    /**
     * Log lines with the level each one would actually be written at, and
     * weighted so a log view is mostly ordinary traffic.
     *
     * Drawing the level independently of the line put ERROR beside "Charge
     * authorised for order 4765" and made a quarter of the stream errors —
     * two things nobody's logs do, on a screen whose whole job is to be read.
     *
     * @var list<array{string, string}>
     */
    private const LINES = [
        ['Processed order %d in 84ms', 'info'],
        ['Cache miss for products:index, rebuilding', 'info'],
        ['Charge authorised for order %d', 'info'],
        ['Session %d expired, re-authenticating', 'info'],
        ['Dispatched App\\Jobs\\SendReceipt for order %d', 'info'],
        ['Resolved %d shipping rates from cache', 'info'],
        ['Scheduled App\\Jobs\\RebuildIndex for order %d', 'info'],
        ['Processed order %d in 84ms', 'info'],
        ['Cache miss for products:index, rebuilding', 'info'],
        ['Charge authorised for order %d', 'info'],
        ['Retrying upstream after 502 (attempt %d)', 'warning'],
        ['Slow query: %dms on `orders`', 'warning'],
        ['Rate limit reached for client %d, throttling', 'warning'],
        ['Failed to charge order %d: card_declined', 'error'],
    ];

    /** Weighted: a handful of pages carry most of the traffic, as they do. */
    private const PATHS = [
        '/', '/', '/products', '/products', '/products/espresso-grinder',
        '/products/kettle', '/products/filter-papers', '/cart',
        '/checkout', '/checkout/complete', '/about', '/blog/roasting-guide',
    ];

    /**
     * Where a visit starts, which is not the same distribution: nobody lands
     * on /checkout/complete.
     *
     * @var list<string>
     */
    private const LANDING_PATHS = [
        '/', '/', '/', '/', '/products', '/products',
        '/products/espresso-grinder', '/blog/roasting-guide', '/blog/roasting-guide', '/about',
    ];

    /**
     * Where visits come from, weighted the way they arrive: mostly direct and
     * organic, with paid, social and email a slice each. A uniform pick drew
     * six channel bars of almost equal length, which is not what any real
     * site looks like.
     *
     * @var list<array{string, string, string, string}> referrer, utm source, medium, campaign
     */
    private const SOURCES = [
        ['', '', '', ''],
        ['', '', '', ''],
        ['', '', '', ''],
        ['', '', '', ''],
        ['https://www.google.com/', '', '', ''],
        ['https://www.google.com/', '', '', ''],
        ['https://duckduckgo.com/', '', '', ''],
        ['https://news.ycombinator.com/item?id=41284417', '', '', ''],
        ['https://t.co/8fQ2xkL', 'twitter', 'social', 'launch-week'],
        ['https://www.google.com/', 'google', 'cpc', 'brand-dk'],
        ['', 'newsletter', 'email', 'september-roast'],
    ];

    /**
     * A few recurring faults: type, message, file and line. The fingerprint is
     * computed from the type and location, so the same fault folds into one
     * group however often it recurs.
     *
     * @var list<array{string, string, string, int}>
     */
    private const FAULTS = [
        ['Illuminate\\Database\\QueryException', 'SQLSTATE[HY000] [2002] Connection refused (Connection: mysql)', 'app/Repositories/OrderRepository.php', 88],
        ['Illuminate\\Http\\Client\\RequestException', 'HTTP request returned status code 502 for https://api.upstream.test/v1/rates', 'app/Services/Rates.php', 41],
        ['GuzzleHttp\\Exception\\ConnectException', 'cURL error 28: Operation timed out after 3000 milliseconds', 'app/Services/Shipping.php', 132],
        ['Illuminate\\Validation\\ValidationException', 'The given data was invalid.', 'app/Http/Requests/CheckoutRequest.php', 27],
        ['RuntimeException', 'Queue worker exceeded memory limit while processing App\\Jobs\\RebuildIndex', 'app/Jobs/RebuildIndex.php', 64],
        ['TypeError', 'Cannot assign string to property App\\Models\\Order::$total of type int', 'app/Models/Order.php', 19],
    ];

    /**
     * The statements an N+1 actually produces: a single-row read in a loop.
     *
     * @var list<string>
     */
    private const DUPLICATED_QUERIES = [
        'select * from `order_lines` where `order_lines`.`order_id` = ? limit 1',
        'select * from `users` where `users`.`id` = ? limit 1',
        'select * from `products` where `products`.`id` = ? limit 1',
        'select count(*) as aggregate from `stock` where `product_id` = ?',
        'select * from `media` where `media`.`model_id` = ? and `media`.`model_type` = ?',
    ];

    /**
     * Weighted toward the home market, as a Danish shop's traffic is.
     *
     * @var list<string>
     */
    private const COUNTRIES = ['DK', 'DK', 'DK', 'DK', 'DK', 'SE', 'SE', 'DE', 'GB', 'US', 'NO'];

    /**
     * The regions and cities that exist inside each country.
     *
     * @var array<string, list<array{string, string}>>
     */
    private const PLACES = [
        'DK' => [['DK-84', 'Copenhagen'], ['DK-84', 'Copenhagen'], ['DK-82', 'Aarhus'], ['DK-83', 'Odense'], ['DK-81', 'Aalborg']],
        'SE' => [['SE-AB', 'Stockholm'], ['SE-O', 'Gothenburg'], ['SE-M', 'Malmo']],
        'DE' => [['DE-BE', 'Berlin'], ['DE-HH', 'Hamburg'], ['DE-BY', 'Munich']],
        'GB' => [['GB-ENG', 'London'], ['GB-ENG', 'Manchester'], ['GB-SCT', 'Edinburgh']],
        'US' => [['US-NY', 'New York'], ['US-CA', 'San Francisco'], ['US-IL', 'Chicago']],
        'NO' => [['NO-03', 'Oslo'], ['NO-46', 'Bergen']],
    ];

    /**
     * The operating systems and browsers each kind of device actually runs.
     *
     * @var array<string, list<array{string, string}>>
     */
    private const CLIENTS = [
        'desktop' => [
            ['macOS', 'Safari'], ['macOS', 'Chrome'], ['Windows', 'Chrome'],
            ['Windows', 'Chrome'], ['Windows', 'Edge'], ['Windows', 'Firefox'], ['Linux', 'Firefox'],
        ],
        'mobile' => [
            ['iOS', 'Safari'], ['iOS', 'Safari'], ['iOS', 'Chrome'],
            ['Android', 'Chrome'], ['Android', 'Chrome'], ['Android', 'Samsung Internet'],
        ],
        'tablet' => [['iPadOS', 'Safari'], ['iPadOS', 'Chrome'], ['Android', 'Chrome']],
    ];

    /**
     * Page views per session, weighted toward one. Every session being the
     * same length made the overview report a bounce rate of exactly 0% and
     * "3.0 views / visit" — two numbers that betray a fixture at a glance.
     *
     * @var list<int>
     */
    private const SESSION_LENGTHS = [1, 1, 1, 1, 1, 1, 2, 2, 2, 3, 3, 4, 5, 7, 11];

    public function __construct(private readonly FixtureData $data = new FixtureData) {}

    public function query(
        LogQuery $query,
        DateTimeInterface $start,
        DateTimeInterface $end,
        int $limit = 100,
    ): array {
        $events = $this->eventsNamedBy($query);

        if (in_array(Analytics::PAGE_VIEW_EVENT, $events, true)) {
            return $this->pageViews($start, $end, $limit);
        }

        if (in_array(Analytics::ENGAGEMENT_EVENT, $events, true)) {
            return $this->engagements($start, $end, $limit);
        }

        if (in_array('app.deployment', $events, true)) {
            return $this->deployments($start, $end);
        }

        // Not every record family is selected by name. A card reading the
        // exception records asks for the records that HAVE a fingerprint
        // (`| exception_group != ""`), and the duplicate-query card asks for
        // the ones that have a statement — the required label is the family.
        $required = $this->labelsRequiredBy($query);

        if (in_array('exception_group', $required, true)) {
            return $this->exceptions($start, $end, $limit);
        }

        if (in_array('db_query_text', $required, true)) {
            return $this->duplicateQueries($start, $end, $limit);
        }

        // An event query this fixture has no records for gets none, rather
        // than prose that the caller would have to discard anyway.
        if ($events !== []) {
            return [];
        }

        return $this->lines($query, $start, $end, $limit);
    }

    public function labelValues(
        string $label,
        ?DateTimeInterface $start = null,
        ?DateTimeInterface $end = null,
    ): array {
        return $this->data->labelValues($label);
    }

    public function probe(): ProbeResult
    {
        return ProbeResult::pass('fixture');
    }

    /**
     * The exact event names a query's line filters select for.
     *
     * A card naming one event uses `|= "analytics.page_view"`; the annotation
     * reader asks for every marker in one round trip with `|~ "app\.deploy…|
     * app\.incident"`, so the alternation is split and unescaped back into
     * the names it was built from.
     *
     * @return list<string>
     */
    private function eventsNamedBy(LogQuery $query): array
    {
        $events = [];

        foreach ($query->pipeline as $stage) {
            if (! $stage instanceof LineFilter) {
                continue;
            }

            $candidates = match ($stage->op) {
                LineOp::Contains => [$stage->value],
                LineOp::Regex => array_map(
                    static fn (string $part): string => stripslashes($part),
                    explode('|', $stage->value),
                ),
                // A negation names what to exclude, never what to return.
                LineOp::NotContains, LineOp::NotRegex => [],
            };

            foreach ($candidates as $candidate) {
                // An event name, not a search term: dotted, no spaces. The
                // log viewer's free-text search goes through the same filter
                // and must not be read as an event.
                if (preg_match('/^[a-z][a-z0-9_]*(\.[a-z0-9_]+)+$/', $candidate) === 1) {
                    $events[] = $candidate;
                }
            }
        }

        return array_values(array_unique($events));
    }

    /**
     * The labels a query insists on having a value for.
     *
     * `| exception_group != ""` is how a card says "the records that carry a
     * fingerprint", which is a family of records rather than a filter over
     * one — so it is dispatch, the same as a line filter naming an event.
     *
     * @return list<string>
     */
    private function labelsRequiredBy(LogQuery $query): array
    {
        $labels = [];

        foreach ($query->pipeline as $stage) {
            if (! $stage instanceof LabelFilter) {
                continue;
            }

            foreach ($stage->matchers as $matcher) {
                if ($matcher->op === MatchOp::Neq && $matcher->value === '') {
                    $labels[] = $matcher->label;
                }
            }
        }

        return array_values(array_unique($labels));
    }

    /**
     * Structured exception records — the backend half of the errors screen.
     * The grouping is this package's fingerprint, carried as a label, which is
     * why the card can group across services without a backend that can.
     *
     * @return list<LogEntry>
     */
    private function exceptions(DateTimeInterface $start, DateTimeInterface $end, int $limit): array
    {
        $from = $start->getTimestamp();
        $span = max(1, $end->getTimestamp() - $from);
        $entries = [];

        // A handful of distinct faults, each recurring — which is what makes
        // the grouped table a grouped table rather than a list.
        $faults = self::FAULTS;
        $budget = min($limit, 400);

        for ($i = 0; $i < $budget; $i++) {
            $fault = $faults[$this->data->int("fault:{$from}:{$i}") % count($faults)];
            $key = "exc:{$from}:{$i}";

            $entries[] = new LogEntry(
                timestampNano: $this->recent($key, $from, $span),
                line: 'app.exception',
                labels: [
                    'exception_group' => $this->data->id('group:'.$fault[0].$fault[2], 10),
                    'exception_type' => $fault[0],
                    'exception_message' => $fault[1],
                    'exception_file' => $fault[2],
                    'exception_line' => (string) $fault[3],
                    'service_name' => $this->data->one("svc:{$key}", FixtureData::SERVICES),
                    'user_id' => $this->data->one("user:{$key}", ['u_4812', 'u_1193', 'u_7740', 'u_2056', '']),
                    'level' => 'error',
                    'deployment_environment' => 'production',
                ],
            );
        }

        return $entries;
    }

    /**
     * Duplicate-query (N+1) records: one per detected repeat, carrying the
     * statement and how many times it ran inside the one request.
     *
     * @return list<LogEntry>
     */
    private function duplicateQueries(DateTimeInterface $start, DateTimeInterface $end, int $limit): array
    {
        $from = $start->getTimestamp();
        $span = max(1, $end->getTimestamp() - $from);
        $entries = [];
        $budget = min($limit, 240);

        for ($i = 0; $i < $budget; $i++) {
            $key = "dup:{$from}:{$i}";
            $statement = $this->data->one("q:{$key}", self::DUPLICATED_QUERIES);

            $entries[] = new LogEntry(
                timestampNano: $this->recent($key, $from, $span),
                line: 'db.query.duplicate_detected',
                labels: [
                    'db_query_text' => $statement,
                    'db_namespace' => $this->data->one("db:{$key}", ['mysql', 'mysql', 'pgsql']),
                    // The point of the card: how bad the N+1 got.
                    'db_query_repeat_count' => (string) (int) $this->data->band("n:{$key}", 4.0, 180.0),
                    'trace_id' => $this->data->id("trace:{$key}"),
                    'service_name' => $this->data->one("svc:{$key}", FixtureData::SERVICES),
                    'deployment_environment' => 'production',
                ],
            );
        }

        return $entries;
    }

    /**
     * One record per view, spread over the window, carrying the dimensions
     * {@see Analytics::rows()} reads.
     *
     * @return list<LogEntry>
     */
    private function pageViews(DateTimeInterface $start, DateTimeInterface $end, int $limit): array
    {
        $from = $start->getTimestamp();
        $to = $end->getTimestamp();
        // Enough rows for a trend line with shape, bounded by what the card
        // asked for; a real Loki read is a bounded sample too.
        $budget = min($limit, 1_200);
        $span = max(1, $to - $from);
        $entries = [];

        // Built a session at a time, because a visit is the unit the screen
        // actually measures: bounce rate is sessions of length one, views per
        // visit is the mean length, and the referrer, device and browser
        // belong to the visitor rather than to each view. Drawing them per
        // view scattered one person across four browsers and three countries.
        for ($session = 0; count($entries) < $budget; $session++) {
            $key = "session:{$from}:{$session}";
            $id = $this->data->id($key, 16);
            $length = $this->data->one("len:{$key}", self::SESSION_LENGTHS);

            [$referrer, $utmSource, $utmMedium, $utmCampaign] = $this->data->one("src:{$key}", self::SOURCES);

            // Sessions arrive on a daily curve rather than uniformly, so the
            // views chart has a shape instead of one flat bar per bucket.
            $position = $this->data->band("at:{$key}", 0.0, 1.0);
            $shaped = min(0.999, max(0.0, $position + 0.16 * sin($position * 6.283185)));
            $arrived = $from + (int) round($shaped * $span);

            // Region and city are drawn FROM the country, not beside it.
            // Drawn independently, the breakdown put DE-BE at the top of the
            // regions while Denmark led the countries by more than double —
            // a screen that cannot be true and that a reader notices.
            $country = $this->data->one("cc:{$key}", self::COUNTRIES);
            [$region, $city] = $this->data->one("place:{$key}", self::PLACES[$country]);

            $device = $this->data->one("dev:{$key}", ['desktop', 'desktop', 'desktop', 'mobile', 'mobile', 'mobile', 'mobile', 'tablet']);

            $visitor = [
                'geo_country_iso_code' => $country,
                'geo_region_iso_code' => $region,
                'geo_locality_name' => $city,
                // And the same nesting for the client: an operating system
                // and a browser belong to a kind of device. Drawn beside it,
                // the audience card reported more mobile visits than iOS and
                // Android visits combined, which means phones running Windows.
                ...self::client($device, $this->data->int("ua:{$key}")),
                'device_type' => $device,
            ];

            for ($view = 0; $view < $length && count($entries) < $budget; $view++) {
                $step = "{$key}:{$view}";

                // Half a minute to a few minutes between pages, and never
                // past the end of the window the card asked about.
                $at = min($to, $arrived + (int) round($view * $this->data->band("gap:{$step}", 25.0, 190.0)));

                $entries[] = new LogEntry(
                    timestampNano: $at * 1_000_000_000,
                    line: Analytics::PAGE_VIEW_EVENT,
                    labels: array_filter([
                        'session_id' => $id,
                        // The landing page is a landing page; later views
                        // wander deeper into the catalogue.
                        'url_path' => $view === 0
                            ? $this->data->one("land:{$step}", self::LANDING_PATHS)
                            : $this->data->one("path:{$step}", self::PATHS),
                        // The whole visit carries the acquisition it arrived
                        // with. Stamping only the landing view left every
                        // later view with no referrer, so the Channels card
                        // read 79% Direct — it was counting depth of visit,
                        // not where the visit came from.
                        'http_request_header_referer' => $referrer,
                        'analytics_utm_source' => $utmSource,
                        'analytics_utm_medium' => $utmMedium,
                        'analytics_utm_campaign' => $utmCampaign,
                        'analytics_source' => 'browser',
                        'service_name' => 'checkout',
                        'deployment_environment' => 'production',
                        ...$visitor,
                    ], static fn (string $value): bool => $value !== ''),
                );
            }
        }

        return $entries;
    }

    /**
     * The operating system and browser of a device of this kind.
     *
     * @return array{os_name: string, user_agent_name: string}
     */
    private static function client(string $device, int $seed): array
    {
        $options = self::CLIENTS[$device] ?? self::CLIENTS['desktop'];
        [$os, $browser] = $options[$seed % count($options)];

        return ['os_name' => $os, 'user_agent_name' => $browser];
    }

    /**
     * Engagement events — visible time per view, which the overview averages.
     *
     * @return list<LogEntry>
     */
    private function engagements(DateTimeInterface $start, DateTimeInterface $end, int $limit): array
    {
        $from = $start->getTimestamp();
        $span = max(1, $end->getTimestamp() - $from);
        $count = min($limit, 600);
        $entries = [];

        for ($i = 0; $i < $count; $i++) {
            $key = "eng:{$from}:{$i}";

            $entries[] = new LogEntry(
                timestampNano: ($from + (int) round($this->data->band("t:{$key}", 0.0, 1.0) * $span)) * 1_000_000_000,
                line: Analytics::ENGAGEMENT_EVENT,
                labels: [
                    'session_id' => $this->data->id('session:'.intdiv($i, 3).':'.$from, 16),
                    // Seconds to a couple of minutes on a page, which is
                    // what the card renders as "avg engagement".
                    'visible_time_ms' => (string) (int) $this->data->band("ms:{$key}", 4_000.0, 128_000.0),
                    'url_path' => $this->data->one("path:{$key}", self::PATHS),
                    'service_name' => 'checkout',
                    'deployment_environment' => 'production',
                ],
            );
        }

        return $entries;
    }

    /**
     * A timestamp inside the window, weighted heavily toward its recent end.
     *
     * These cards search a wide window — days, to answer "first seen" — and
     * then display a much narrower one. Spread evenly, a few hundred records
     * over a week put about one inside the hour on screen, and the errors
     * table reported a group with one event and four affected users.
     */
    private function recent(string $key, int $from, int $span): int
    {
        $u = $this->data->band("t:{$key}", 0.0, 1.0);

        return (int) ($from + $span - (int) round($u ** 3 * $span)) * 1_000_000_000;
    }

    /**
     * Sparse deploy markers, which is what makes the charts show them.
     *
     * @return list<LogEntry>
     */
    private function deployments(DateTimeInterface $start, DateTimeInterface $end): array
    {
        $from = $start->getTimestamp();
        $to = $end->getTimestamp();
        $entries = [];

        // Walked BACKWARDS from now, because the annotation reader asks over a
        // multi-day lookback and every card then filters that down to its own
        // (often six-hour) window. Generated forward from the start of the
        // lookback and capped, every marker landed days before the window the
        // dashboard was showing, and the deploys card read "No deploys in this
        // period" against a fixture that had just returned forty of them.
        $step = 5 * 3_600;

        for ($n = 0, $at = $to - 900; $at >= $from && $n < 40; $n++, $at -= $step) {
            $key = "deploy:{$n}";

            $entries[] = new LogEntry(
                timestampNano: $at * 1_000_000_000,
                line: 'app.deployment',
                labels: [
                    // Distinct per marker: the reader clusters by label, so a
                    // shared id would collapse a week of deploys into one row.
                    'deployment_id' => 'v3.'.(40 - $n).'.'.($n % 3),
                    'deployment_notes' => $this->data->one("notes:{$key}", [
                        'Cache the route facets',
                        'Bump the queue worker count',
                        'Fix the receipt mailer',
                        'Semconv attribute rename',
                        'Drop the legacy span attributes',
                    ]),
                    'service_name' => $this->data->one("svc:{$key}", FixtureData::SERVICES),
                    'deployment_environment' => 'production',
                ],
            );
        }

        return $entries;
    }

    /**
     * Ordinary log lines, honouring a free-text search so the log viewer's
     * search box does something.
     *
     * @return list<LogEntry>
     */
    private function lines(LogQuery $query, DateTimeInterface $start, DateTimeInterface $end, int $limit): array
    {
        $seed = $this->data->id($query->raw ?? (json_encode($query->stream) ?: 'logs'), 12);
        $from = $start->getTimestamp();
        $anchor = $end->getTimestamp();
        // Across the window the card is showing, not the last three minutes
        // of it: sixty lines three seconds apart drew an hour of "lines over
        // time" as a single bar at the right-hand edge.
        $gap = max(1, intdiv(max(1, $anchor - $from), max(1, min($limit, 60))));
        $needles = [];

        foreach ($query->pipeline as $stage) {
            if ($stage instanceof LineFilter && $stage->op === LineOp::Contains) {
                $needles[] = $stage->value;
            }
        }

        $entries = [];

        for ($i = min($limit, 60) - 1; $i >= 0; $i--) {
            $key = "log:{$seed}:{$i}";

            [$template, $level] = $this->data->one($key, self::LINES);

            $line = sprintf($template, (int) $this->data->band("n:{$key}", 1_000.0, 9_999.0));

            foreach ($needles as $needle) {
                if (! str_contains(strtolower($line), strtolower($needle))) {
                    continue 2;
                }
            }

            $entries[] = new LogEntry(
                // Jittered, because lines exactly sixty seconds apart are a
                // cron job, not an application.
                timestampNano: (int) (($anchor - $i * $gap - (int) $this->data->band("j:{$key}", 0.0, (float) $gap)) * 1_000_000_000),
                line: $line,
                labels: [
                    'service_name' => $this->data->one("svc:{$key}", FixtureData::SERVICES),
                    'level' => $level,
                    'deployment_environment' => 'production',
                ],
            );
        }

        return $entries;
    }
}
