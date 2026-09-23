<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Panels\Builtin\Detail;

use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Panels\Panel;
use Cbox\TelemetryUi\Panels\Ui;
use Cbox\TelemetryUi\Support\Format;

/**
 * The header of a host-detail page: the machine, a link back to the list,
 * and its headline numbers (CPU, memory, load, request volume) right now.
 */
final class HostDetailHeader extends Panel
{
    use ScopesToMachine;

    public static function span(): int
    {
        return 2;
    }

    /**
     * @return array<string, mixed>
     */
    public function data(): array
    {
        $error = null;
        $cpu = $memory = $load = $requests = null;

        try {
            $cpu = $this->total($this->metric('', '__name__=~"system_cpu_utilization(_ratio)?"')->avgBy());
            $memory = $this->total($this->metric('', '__name__=~"system_memory_utilization(_ratio)?",state="used"')->avgBy());
            $load = $this->total($this->metric('', '__name__=~"system_cpu_load_average(_ratio)?", period="1m"')->avgBy());
            $requests = $this->total($this->metric('http_server_request_duration_seconds_count')->increase($this->promDuration())->sumBy());
        } catch (SourceException $exception) {
            $error = $exception->getMessage();
        }

        return Ui::header($this->host === '' ? '(all hosts)' : $this->host, 'Host detail', [
            ['label' => 'CPU', 'value' => $cpu !== null && ! is_nan($cpu) ? Format::percent($cpu) : '—', 'tone' => $cpu !== null && $cpu > 0.85 ? 'danger' : null],
            ['label' => 'Memory', 'value' => $memory !== null && ! is_nan($memory) ? Format::percent($memory) : '—', 'tone' => $memory !== null && $memory > 0.9 ? 'danger' : 'dim'],
            ['label' => 'Load 1m', 'value' => $load !== null && ! is_nan($load) ? rtrim(rtrim(number_format($load, 2), '0'), '.') : '—', 'tone' => 'dim'],
            ['label' => 'Requests', 'value' => $requests !== null && ! is_nan($requests) ? Format::count($requests) : '—', 'tone' => 'dim'],
        ], [
            'back' => [...Ui::page('hosts'), 'label' => '← All hosts'],
            'error' => $error,
            'span' => 2,
        ]);
    }

    protected function statLinks(): array
    {
        return ['Requests' => Ui::explore('requests', ['host.name='.$this->host])];
    }
}
