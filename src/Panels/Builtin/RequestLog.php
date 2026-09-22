<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Panels\Builtin;

use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Panels\Attributes\Param;
use Cbox\TelemetryUi\Panels\Concerns\CoercesAttributes;
use Cbox\TelemetryUi\Panels\Panel;
use Cbox\TelemetryUi\Panels\Ui;
use Cbox\TelemetryUi\Queries\Ir\TraceCondition;
use Cbox\TelemetryUi\Queries\Ir\TraceOp;
use Cbox\TelemetryUi\Support\Format;

/**
 * The request LOG: individual requests, newest first — the live-tail view
 * for production debugging. Filter down to one user or one client IP, hit
 * Live, and watch their requests arrive; every row opens the readable
 * request story. The Routes panel is the grouped sibling, shown alongside.
 */
class RequestLog extends Panel
{
    use CoercesAttributes;

    #[Param('log_ip')]
    public string $ip = '';

    #[Param('log_user')]
    public string $user = '';

    #[Param('log_path')]
    public string $path = '';

    #[Param('log_status')]
    public string $statusCode = '';

    public static function span(): int
    {
        return 2;
    }

    public function data(): array
    {
        [$start, $end] = $this->range();

        $rows = [];
        $error = null;

        try {
            // kind=server alone excludes browser/RUM spans (they are client/
            // internal) — and `span.browser != true` would wrongly drop every
            // backend span too, since TraceQL can't evaluate a missing attr.
            $conditions = [
                TraceCondition::token('kind', TraceOp::Eq, 'server'),
                ...$this->extraTraceConditions(),
            ];

            if ($this->ip !== '') {
                $conditions[] = TraceCondition::eq('span.client.address', $this->ip);
            }

            if ($this->user !== '') {
                $conditions[] = TraceCondition::eq('span.user.id', $this->user);
            }

            if ($this->path !== '') {
                $conditions[] = TraceCondition::re('span.url.path', '.*'.preg_quote($this->path, '/').'.*');
            }

            if (preg_match('/^([1-5])xx$/', $this->statusCode, $m) === 1) {
                $conditions[] = TraceCondition::token('span.http.response.status_code', TraceOp::Gte, $m[1].'00');
                $conditions[] = TraceCondition::token('span.http.response.status_code', TraceOp::Lt, ((int) $m[1] + 1).'00');
            }

            $query = $this->traceQuery(...$conditions)
                ->select('span.http.request.method', 'span.url.path', 'span.http.route', 'span.http.response.status_code', 'span.client.address', 'span.user.id', 'span.livewire.components');

            foreach ($this->traces()->search($query, $start, $end, limit: 50) as $summary) {
                $attributes = isset($summary->matchedSpans[0]) ? $summary->matchedSpans[0]->attributes : [];

                $path = $this->str($attributes['url.path'] ?? $attributes['http.route'] ?? null) ?? $summary->rootTraceName;
                $route = $this->str($attributes['http.route'] ?? null) ?? '';

                // A Livewire update URL identifies nothing — show the
                // component(s) the request actually touched instead.
                if (($livewire = $this->str($attributes['livewire.components'] ?? null)) !== null && $livewire !== '') {
                    $path = 'livewire:'.$livewire;
                } elseif (str_starts_with($route, 'livewire:')) {
                    $path = $route;
                }

                $rows[] = [
                    'traceId' => $summary->traceId,
                    'startedAt' => $summary->startedAt,
                    'durationMs' => $summary->durationMs,
                    'service' => $summary->rootServiceName,
                    'method' => $this->str($attributes['http.request.method'] ?? null) ?? '',
                    'path' => $path,
                    'status' => $this->str($attributes['http.response.status_code'] ?? null) ?? '',
                    'ip' => $this->str($attributes['client.address'] ?? null) ?? '',
                    'user' => $this->str($attributes['user.id'] ?? null) ?? '',
                ];
            }

            usort($rows, static fn (array $a, array $b): int => $b['startedAt'] <=> $a['startedAt']);
        } catch (SourceException $exception) {
            $error = $exception->getMessage();
        }

        return Ui::table('Request log', [
            Ui::col('time', 'Time'),
            Ui::col('request', 'Request'),
            Ui::num('status', 'Status'),
            Ui::num('user', 'User'),
            Ui::num('ip', 'IP'),
            Ui::num('duration', 'Duration'),
        ], array_map($this->row(...), $rows), array_filter([
            'subtitle' => 'Individual requests, newest first — filter to a user or IP and go live to tail production',
            'error' => $error,
            'empty' => 'No requests match — widen the filters or the period.',
            'note' => 'Newest 50 within the period. Click user/IP to tail them; click a row for the full request story.',
            'controls' => [
                Ui::search('log_user', 'User', $this->user, 'User id…'),
                Ui::search('log_ip', 'Client IP', $this->ip, 'Client IP…'),
                Ui::search('log_path', 'Path', $this->path, 'Path contains…'),
                Ui::select('log_status', 'Status class', $this->statusCode, [
                    ['value' => '', 'label' => 'Any'],
                    ['value' => '2xx', 'label' => '2xx'],
                    ['value' => '3xx', 'label' => '3xx'],
                    ['value' => '4xx', 'label' => '4xx'],
                    ['value' => '5xx', 'label' => '5xx'],
                ]),
            ],
            // Live tail: the SPA subscribes to the requests stream with the
            // current filters and prepends arrivals, newest on top.
            'stream' => [
                'signal' => 'requests',
                'params' => array_filter([
                    'panel' => static::id(),
                    'log_ip' => $this->ip,
                    'log_user' => $this->user,
                    'log_path' => $this->path,
                    'log_status' => $this->statusCode,
                ], static fn (string $v): bool => $v !== ''),
            ],
        ], static fn ($v): bool => $v !== null));
    }

    /**
     * @param  array{traceId: string, startedAt: \DateTimeImmutable, durationMs: float, service: string, method: string, path: string, status: string, ip: string, user: string}  $row
     * @return array<string, mixed>
     */
    private function row(array $row): array
    {
        $status = $row['status'];

        return [
            'time' => Ui::cell($row['startedAt']->format('H:i:s'), ['raw' => $row['startedAt']->getTimestamp(), 'mono' => true]),
            'request' => Ui::cell(trim($row['method'].' '.$row['path']), ['mono' => true]),
            'status' => $status === ''
                ? Ui::cell(null)
                : Ui::cell($status, ['raw' => (int) $status, 'badge' => $status, 'tone' => match (true) {
                    str_starts_with($status, '5') => 'danger',
                    str_starts_with($status, '4') => 'warn',
                    default => 'ok',
                }]),
            // Click a user/IP to tail them: sets this panel's own filter.
            'user' => $row['user'] === ''
                ? Ui::cell('—')
                : Ui::cell('#'.$row['user'], ['mono' => true, 'link' => Ui::param('log_user', $row['user']), 'dim' => ['key' => 'user.id', 'value' => $row['user']]]),
            'ip' => $row['ip'] === ''
                ? Ui::cell('—')
                : Ui::cell($row['ip'], ['mono' => true, 'link' => Ui::param('log_ip', $row['ip']), 'dim' => ['key' => 'client.address', 'value' => $row['ip']]]),
            'duration' => Ui::cell(Format::ms($row['durationMs']), ['raw' => $row['durationMs'], 'tone' => 'dim']),
            '_link' => Ui::trace($row['traceId']),
        ];
    }

    /**
     * Extra span conditions AND-ed into the search — a subclass narrows the
     * log to its slice (e.g. Livewire update requests only).
     *
     * @return list<TraceCondition>
     */
    protected function extraTraceConditions(): array
    {
        return [];
    }
}
