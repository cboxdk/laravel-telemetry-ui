<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Cards\Builtin;

use Cbox\TelemetryUi\Cards\Card;
use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Queries\Results\LogEntry;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Url;

/**
 * Live log viewer over Loki, with text search and trace-id linkification.
 */
final class LogViewer extends Card
{
    #[Url(as: 'log_search')]
    public string $search = '';

    public function render(): View
    {
        [$start, $end] = $this->range();

        $entries = [];
        $error = null;

        try {
            $logql = $this->logSelector();

            if ($this->search !== '') {
                $logql .= ' |= "'.addcslashes($this->search, '"\\').'"';
            }

            $entries = $this->logs()->query($logql, $start, $end, limit: 200);
        } catch (SourceException $exception) {
            $error = $exception->getMessage();
        }

        /** @var view-string $view */
        $view = 'telemetry-ui::cards.log-viewer';

        return view($view, [
            'entries' => array_reverse($entries),
            'error' => $error,
        ]);
    }

    /**
     * Severity bucket for a log line, for coloring: the level/detected_level
     * stream label when present, otherwise a light heuristic on the line.
     */
    public function level(LogEntry $entry): string
    {
        $level = strtolower($entry->labels['level'] ?? $entry->labels['detected_level'] ?? '');

        if ($level === '') {
            $line = strtolower(substr($entry->line, 0, 200));

            $level = match (true) {
                str_contains($line, 'error') || str_contains($line, 'exception') || str_contains($line, 'critical') => 'error',
                str_contains($line, 'warn') => 'warning',
                str_contains($line, 'debug') => 'debug',
                default => 'info',
            };
        }

        return match (true) {
            in_array($level, ['error', 'critical', 'alert', 'emergency', 'fatal'], true) => 'danger',
            in_array($level, ['warn', 'warning'], true) => 'warn',
            in_array($level, ['debug', 'trace'], true) => 'dim',
            default => 'info',
        };
    }

    /**
     * Link 32-hex trace ids in a log line to the trace page. Returns HTML;
     * everything except the injected anchors is escaped.
     */
    public function linkify(string $line): string
    {
        $escaped = e($line);

        return (string) preg_replace_callback(
            '/\b([0-9a-f]{32})\b/',
            static fn (array $matches): string => '<a href="'.route('telemetry-ui.trace', ['traceId' => $matches[1]]).'">'.$matches[1].'</a>',
            $escaped,
        );
    }
}
