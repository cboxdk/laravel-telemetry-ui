<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Cards\Builtin;

use Cbox\TelemetryUi\Cards\Card;
use Cbox\TelemetryUi\Connectors\SourceException;
use DateTimeImmutable;
use Illuminate\Contracts\View\View;

/**
 * Recently active users, sampled from traces carrying enduser.* attributes.
 */
final class ActiveUsers extends Card
{
    public function render(): View
    {
        [$start, $end] = $this->range();

        $users = [];
        $error = null;

        try {
            $traceql = '{ '.$this->traceScope('span.enduser.id != nil').' } | select(span.enduser.id, span.enduser.guard, span.enduser.type)';

            $results = $this->traces()->search($traceql, $start, $end, limit: 100);

            foreach ($results as $summary) {
                foreach ($summary->matchedSpans as $span) {
                    $id = $span->attributes['enduser.id'] ?? null;

                    if ($id === null || $id === '') {
                        continue;
                    }

                    $guard = is_string($span->attributes['enduser.guard'] ?? null) ? $span->attributes['enduser.guard'] : 'web';
                    $key = $guard.'#'.(is_scalar($id) ? (string) $id : '?');

                    $users[$key] ??= [
                        'id' => is_scalar($id) ? (string) $id : '?',
                        'guard' => $guard,
                        'traces' => 0,
                        'lastSeen' => $summary->startedAt,
                        'lastAction' => $summary->rootTraceName,
                    ];

                    $users[$key]['traces']++;

                    if ($summary->startedAt > $users[$key]['lastSeen']) {
                        $users[$key]['lastSeen'] = $summary->startedAt;
                        $users[$key]['lastAction'] = $summary->rootTraceName;
                    }
                }
            }

            usort($users, static fn (array $a, array $b): int => $b['lastSeen'] <=> $a['lastSeen']);
        } catch (SourceException $exception) {
            $error = $exception->getMessage();
        }

        /** @var view-string $view */
        $view = 'telemetry-ui::cards.active-users';

        return view($view, [
            'users' => array_slice($users, 0, 100),
            'error' => $error,
            'now' => new DateTimeImmutable,
        ]);
    }

    public function tracesUrl(string $id): string
    {
        return route('telemetry-ui.page', array_filter([
            'page' => 'traces',
            'q' => '{ '.$this->traceScope('span.enduser.id = "'.addcslashes($id, '"\\').'"').' }',
            'period' => $this->period,
            'service' => $this->service,
            'env' => $this->environment,
        ]));
    }
}
