<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Panels\Builtin;

use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Panels\Attributes\Param;
use Cbox\TelemetryUi\Panels\Panel;
use Cbox\TelemetryUi\Panels\Ui;
use Cbox\TelemetryUi\Queries\Ir\LabelFilter;
use Cbox\TelemetryUi\Queries\Ir\LabelMatcher;
use Cbox\TelemetryUi\Queries\Ir\MatchOp;
use Cbox\TelemetryUi\Queries\Results\LogEntry;
use Cbox\TelemetryUi\Support\ScopeLabels;

/**
 * Log viewer over Loki. Every line is correlated with its trace: the
 * OTLP log bridge stamps trace_id/span_id into the stream metadata, so a
 * line can link straight to its request waterfall.
 */
final class LogViewer extends Panel
{
    public static function span(): int
    {
        return 2;
    }

    #[Param('log_search')]
    public string $search = '';

    #[Param('log_level')]
    public string $level = '';

    /** @var list<string> */
    public array $levels = ['error', 'warning', 'info', 'debug'];

    public function data(): array
    {
        [$start, $end] = $this->range();

        $rows = [];
        $error = null;

        try {
            $query = $this->logSelector();

            if ($this->level !== '') {
                // Loki keeps the severity as either `level` or `detected_level`.
                $pattern = '(?i)'.$this->levelPattern();
                $query = $query->pipe(new LabelFilter([
                    new LabelMatcher('level', MatchOp::Re, $pattern),
                    new LabelMatcher('detected_level', MatchOp::Re, $pattern),
                ], or: true));
            }

            if ($this->search !== '') {
                $query = $query->lineContains($this->search);
            }

            $entries = $this->logs()->query($query, $start, $end, limit: 200);

            $rows = array_map(fn (LogEntry $entry): array => $this->row($entry), array_reverse($entries));
        } catch (SourceException $exception) {
            $error = $exception->getMessage();
        }

        return [
            'kind' => 'logs',
            'title' => 'Logs',
            'subtitle' => 'Trace-correlated log lines from Loki — click a line for metadata, jump to its trace',
            'span' => 2,
            'entries' => $rows,
            // Live tail: the SPA opens /api/v2/stream/logs with the same filters.
            'stream' => ['signal' => 'logs', 'params' => array_filter([
                'log_search' => $this->search,
                'log_level' => $this->level,
            ], static fn (string $v): bool => $v !== '')],
            'error' => $error,
            'empty' => 'No log lines in this period. Route the "telemetry" log channel to OTLP to see logs here.',
            'note' => $rows !== [] ? 'Most recent 200 lines, oldest first.' : null,
            'controls' => [
                Ui::select('log_level', 'Level', $this->level, [
                    ['value' => '', 'label' => 'All levels'],
                    ...array_map(static fn (string $lvl): array => ['value' => $lvl, 'label' => ucfirst($lvl)], $this->levels),
                ]),
                Ui::search('log_search', 'Filter', $this->search, 'Filter log lines…'),
            ],
        ];
    }

    /**
     * One `logs` entry. The service rides in the labels (v1 showed it as a
     * badge); the rest of the labels are the structured metadata worth
     * surfacing.
     *
     * @return array{time: string, ms: int, level: string, tone: string, message: string, labels: array<string, string>, traceId?: string}
     */
    private function row(LogEntry $entry): array
    {
        $traceId = $entry->labels['trace_id'] ?? null;
        $traceId = is_string($traceId) && $traceId !== '' ? $traceId : null;

        // Structured metadata worth surfacing; the rest is boilerplate.
        $hidden = [ScopeLabels::logs('service'), 'trace_id', 'level', 'detected_level', 'severity_number', 'scope_name'];
        $labels = [];
        $service = $entry->labels[ScopeLabels::logs('service')] ?? '';

        if ($service !== '') {
            $labels['service'] = $service;
        }

        foreach ($entry->labels as $key => $value) {
            if (! in_array($key, $hidden, true)) {
                $labels[$key] = $value;
            }
        }

        $row = [
            'time' => $entry->timestamp()->format('H:i:s.v'),
            'ms' => intdiv($entry->timestampNano, 1_000_000),
            'level' => strtoupper($entry->labels['level'] ?? $entry->labels['detected_level'] ?? $this->inferLevel($entry->line)),
            'tone' => $this->tone($entry),
            'message' => $entry->line,
            'labels' => $labels,
        ];

        if ($traceId !== null) {
            $row['traceId'] = $traceId;
        }

        return $row;
    }

    private function tone(LogEntry $entry): string
    {
        $level = strtolower($entry->labels['level'] ?? $entry->labels['detected_level'] ?? $this->inferLevel($entry->line));

        return match (true) {
            in_array($level, ['error', 'critical', 'alert', 'emergency', 'fatal'], true) => 'danger',
            in_array($level, ['warn', 'warning'], true) => 'warn',
            in_array($level, ['debug', 'trace'], true) => 'dim',
            default => 'info',
        };
    }

    private function inferLevel(string $line): string
    {
        $line = strtolower(substr($line, 0, 200));

        return match (true) {
            str_contains($line, 'error') || str_contains($line, 'exception') || str_contains($line, 'critical') => 'error',
            str_contains($line, 'warn') => 'warning',
            str_contains($line, 'debug') => 'debug',
            default => 'info',
        };
    }

    private function levelPattern(): string
    {
        return match ($this->level) {
            'error' => 'error|critical|alert|emergency|fatal',
            'warning' => 'warn|warning',
            'debug' => 'debug|trace',
            default => preg_quote($this->level, '/'),
        };
    }
}
