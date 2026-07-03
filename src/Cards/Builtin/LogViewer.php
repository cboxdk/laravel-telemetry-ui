<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Cards\Builtin;

use Cbox\TelemetryUi\Cards\Card;
use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Queries\Results\LogEntry;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Url;

/**
 * Log viewer over Loki. Every line is correlated with its trace: the
 * OTLP log bridge stamps trace_id/span_id into the stream metadata, so a
 * line can link straight to its request waterfall.
 */
final class LogViewer extends Card
{
    #[Url(as: 'log_search')]
    public string $search = '';

    #[Url(as: 'log_level')]
    public string $level = '';

    /** @var list<string> */
    public array $levels = ['error', 'warning', 'info', 'debug'];

    public function render(): View
    {
        [$start, $end] = $this->range();

        $rows = [];
        $error = null;

        try {
            $logql = $this->logSelector();

            if ($this->level !== '') {
                // Loki keeps the severity as either `level` or `detected_level`.
                $logql .= ' | level=~"(?i)'.$this->levelPattern().'" or detected_level=~"(?i)'.$this->levelPattern().'"';
            }

            if ($this->search !== '') {
                $logql .= ' |= "'.addcslashes($this->search, '"\\').'"';
            }

            $entries = $this->logs()->query($logql, $start, $end, limit: 200);

            $rows = array_map(fn (LogEntry $entry): array => $this->row($entry), array_reverse($entries));
        } catch (SourceException $exception) {
            $error = $exception->getMessage();
        }

        /** @var view-string $view */
        $view = 'telemetry-ui::cards.log-viewer';

        return view($view, ['rows' => $rows, 'error' => $error]);
    }

    /**
     * @return array{time: string, tone: string, level: string, service: string, message: string, traceId: string|null, traceUrl: string|null, meta: array<string, string>}
     */
    private function row(LogEntry $entry): array
    {
        $traceId = $entry->labels['trace_id'] ?? null;
        $traceId = is_string($traceId) && $traceId !== '' ? $traceId : null;

        // Structured metadata worth surfacing; the rest is boilerplate.
        $hidden = ['service_name', 'trace_id', 'level', 'detected_level', 'severity_number', 'scope_name'];
        $meta = [];

        foreach ($entry->labels as $key => $value) {
            if (! in_array($key, $hidden, true)) {
                $meta[$key] = $value;
            }
        }

        return [
            'time' => $entry->timestamp()->format('H:i:s.v'),
            'tone' => $this->tone($entry),
            'level' => strtoupper($entry->labels['level'] ?? $entry->labels['detected_level'] ?? $this->inferLevel($entry->line)),
            'service' => $entry->labels['service_name'] ?? '',
            'message' => $entry->line,
            'traceId' => $traceId,
            'traceUrl' => $traceId !== null ? route('telemetry-ui.trace', [
                'traceId' => $traceId,
                'period' => $this->period,
                'from' => $this->from,
                'to' => $this->to,
                'service' => $this->service,
                'env' => $this->environment,
            ]) : null,
            'meta' => $meta,
        ];
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
