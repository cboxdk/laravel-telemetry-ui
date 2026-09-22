<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Panels\Builtin\Detail;

use Cbox\TelemetryUi\Analysis\ErrorGroupReport;
use Cbox\TelemetryUi\Connectors\ConnectionManager;
use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Contracts\CreatesIssues;
use Cbox\TelemetryUi\Panels\Panel;
use Cbox\TelemetryUi\Panels\Ui;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * The issue page's event deep-dive — message, facts, request strip,
 * root-cause hints (suspect deploy + release spread), source context,
 * stacktrace and recent occurrences — as one composite panel.
 *
 * @phpstan-import-type Report from ErrorGroupReport
 * @phpstan-import-type Link from Ui
 *
 * @phpstan-type KvItem array{label: string, value: string|int|float|null, mono?: bool, link?: Link, tone?: string}
 */
final class ErrorGroupDetail extends Panel
{
    use ScopesToGroup;

    public static function span(): int
    {
        return 2;
    }

    private const TITLE = 'Latest occurrence';

    public function data(): array
    {
        $extra = [
            'subtitle' => "The newest event's full detail — request, root cause, source context and stacktrace",
            'span' => 2,
        ];

        if (! ErrorGroupReport::validId($this->group)) {
            return Ui::composite(self::TITLE, [], [...$extra, 'error' => 'Not a valid error-group id.']);
        }

        try {
            $report = $this->groupReport();
        } catch (SourceException $exception) {
            return Ui::composite(self::TITLE, [], [...$extra, 'error' => $exception->getMessage()]);
        }

        $stats = $report['stats'];

        if ($stats === null) {
            return Ui::composite(self::TITLE, [], [
                ...$extra,
                'empty' => 'No occurrences of this error group in the last '.ErrorGroupReport::LOOKBACK_DAYS.' days (within your scope).',
            ]);
        }

        $detail = $report['detail'];
        $parts = [];

        if ($detail !== null && $detail['message'] !== '') {
            $parts[] = Ui::callout($detail['type'], $detail['message'], 'danger');
        }

        $parts[] = Ui::kv('Facts', $this->groupFacts($report, withCount: true));

        if ($report['request'] !== null) {
            $parts[] = Ui::kv('Latest occurrence', $this->requestItems($report['request']));
        }

        if ($report['suspect'] !== null || $report['releases'] !== []) {
            $parts[] = Ui::kv('Root cause hints', $this->hintItems($report));
        }

        if ($detail !== null && $detail['source'] !== '') {
            $lines = explode("\n", $detail['source']);
            $parts[] = Ui::code('Source · '.$detail['file'].':'.$detail['line'], $detail['source'], 'php', [
                // 1-based line numbers of the throw line(s) — marked "> " in the snippet.
                'highlight' => array_keys(array_filter(
                    array_combine(range(1, count($lines)), $lines),
                    static fn (string $line): bool => str_starts_with($line, '> '),
                )),
            ]);
        }

        if ($detail !== null && $detail['stacktrace'] !== '') {
            $parts[] = Ui::code('Stacktrace · latest occurrence', $detail['stacktrace'], 'stacktrace');
        } elseif ($stats['source'] === 'frontend') {
            $parts[] = Ui::callout('', 'Browser errors carry no stacktrace — the SDK ships type, message and file:line only.');
        }

        $parts[] = $this->occurrencesTable($report);

        $manager = app(ConnectionManager::class);
        $canCreate = Gate::allows('manageTelemetryUi')
            && $manager->hasIssues()
            && $manager->issues() instanceof CreatesIssues;

        return Ui::composite(self::TITLE, $parts, [
            ...$extra,
            'note' => 'Occurrences and counts are a sample of the last '.ErrorGroupReport::LOOKBACK_DAYS.' days'
                .($stats['sampled'] ? ' (capped — the real total is higher)' : '').'.',
            // Prefilled draft for the SPA's compose-ticket form (POST /api/v2/issues).
            'ticket' => $canCreate ? app(ErrorGroupReport::class)->draft($this->group, $stats, $detail) : null,
        ]);
    }

    /**
     * The request/job that hit it (Sentry's "which request?"), off the newest
     * occurrence's trace root.
     *
     * @param  array{traceId: string, origin: string, method: string, route: string, status: string, user: string}  $request
     * @return list<KvItem>
     */
    private function requestItems(array $request): array
    {
        $items = [];

        if ($request['route'] !== '') {
            $items[] = [
                'label' => $request['method'] !== '' ? $request['method'] : 'route',
                'value' => $request['route'],
                'mono' => true,
                'link' => Ui::entity('route', $request['route']),
            ];
        } elseif ($request['origin'] !== '') {
            $items[] = ['label' => 'origin', 'value' => $request['origin']];
        }

        if ($request['status'] !== '') {
            $status = ['label' => 'status', 'value' => $request['status'], 'mono' => true];

            if (str_starts_with($request['status'], '5')) {
                $status['tone'] = 'danger';
            }

            $items[] = $status;
        }

        if ($request['user'] !== '') {
            $items[] = ['label' => 'user', 'value' => '#'.$request['user']];
        }

        $items[] = ['label' => 'trace', 'value' => '⇄ '.substr($request['traceId'], 0, 12).'…', 'mono' => true, 'link' => Ui::trace($request['traceId'])];

        return $items;
    }

    /**
     * Root-cause hints: the change event closest before first-seen, and which
     * releases the sampled occurrences carry.
     *
     * @param  Report  $report
     * @return list<KvItem>
     */
    private function hintItems(array $report): array
    {
        $items = [];
        $suspect = $report['suspect'];

        if ($suspect !== null) {
            $item = [
                'label' => 'Suspect',
                'value' => $suspect['label'].' at '.$suspect['time'].' — first seen '.$suspect['gap'].' later'
                    .($suspect['notes'] !== null && $suspect['notes'] !== '' ? ' · '.Str::limit($suspect['notes'], 80) : ''),
            ];

            if ($suspect['traceId'] !== null && $suspect['traceId'] !== '') {
                $item['link'] = Ui::trace($suspect['traceId']);
            }

            $items[] = $item;
        }

        if ($report['releases'] !== []) {
            $only = count($report['releases']) === 1 && ($report['stats']['count'] ?? 0) > 1;

            $seen = [
                'label' => 'Seen in',
                'value' => implode(', ', array_map(
                    static fn (array $release): string => $release['release'].' · '.$release['count'],
                    $report['releases'],
                )).($only ? ' — only this release' : ''),
                'mono' => true,
            ];

            if ($only) {
                $seen['tone'] = 'warn';
            }

            $items[] = $seen;
        }

        return $items;
    }

    /**
     * @param  Report  $report
     * @return array<string, mixed>
     */
    private function occurrencesTable(array $report): array
    {
        $rows = [];

        foreach (array_slice($report['occurrences'], 0, 20) as $occurrence) {
            $row = [
                'when' => Ui::cell($occurrence['at'], ['raw' => intdiv($occurrence['nano'], 1_000_000), 'mono' => true]),
                'source' => Ui::cell($occurrence['frontend'] ? 'web' : 'server', [
                    'badge' => $occurrence['frontend'] ? 'web' : 'server',
                    'tone' => $occurrence['frontend'] ? null : 'info',
                ]),
                'service' => Ui::cell($occurrence['service']),
                'message' => Ui::cell(Str::limit($occurrence['message'], 120)),
                'trace' => $occurrence['traceId'] !== ''
                    ? Ui::cell(substr($occurrence['traceId'], 0, 8).'…', ['mono' => true, 'link' => Ui::trace($occurrence['traceId'])])
                    : Ui::cell('logs →', ['tone' => 'dim', 'link' => Ui::around('logs', intdiv($occurrence['nano'], 1_000_000_000), 30, 30)]),
            ];

            // Sampled-away or trace-less occurrences still lead somewhere:
            // the log lines around that moment, this group's record included.
            $row['_link'] = $occurrence['traceId'] !== ''
                ? Ui::trace($occurrence['traceId'])
                : Ui::around('logs', intdiv($occurrence['nano'], 1_000_000_000), 30, 30);

            $rows[] = $row;
        }

        return Ui::table('Recent occurrences', [
            Ui::col('when', 'When'),
            Ui::col('source', 'Source'),
            Ui::col('service', 'Service'),
            Ui::col('message', 'Message'),
            Ui::num('trace', 'Trace'),
        ], $rows);
    }
}
