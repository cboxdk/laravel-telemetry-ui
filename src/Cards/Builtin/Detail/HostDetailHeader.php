<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Cards\Builtin\Detail;

use Cbox\TelemetryUi\Cards\Card;
use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Support\Format;
use Illuminate\Contracts\View\View;

/**
 * The header of a host-detail page: the machine, a link back to the list,
 * and its headline numbers (CPU, memory, load, request volume) right now.
 */
final class HostDetailHeader extends Card
{
    use ScopesToMachine;

    public function render(): View
    {
        $error = null;
        $cpu = $memory = $load = $requests = null;

        try {
            $cpu = $this->total('avg('.$this->metric('system_cpu_utilization_ratio').')');
            $memory = $this->total('avg('.$this->metric('system_memory_utilization_ratio', 'state="used"').')');
            $load = $this->total('avg('.$this->metric('', '__name__=~"system_cpu_load_average(_ratio)?", period="1m"').')');
            $requests = $this->total('sum(increase('.$this->metric('http_server_request_duration_milliseconds_count').'['.$this->promDuration().']))');
        } catch (SourceException $exception) {
            $error = $exception->getMessage();
        }

        /** @var view-string $view */
        $view = 'telemetry-ui::cards.detail-header';

        return view($view, [
            'title' => $this->host === '' ? '(all hosts)' : $this->host,
            'subtitle' => 'Host detail',
            'backUrl' => $this->pageUrl('hosts'),
            'backLabel' => '← All hosts',
            'error' => $error,
            'stats' => [
                ['label' => 'CPU', 'value' => $cpu !== null && ! is_nan($cpu) ? Format::percent($cpu) : '—', 'tone' => $cpu !== null && $cpu > 0.85 ? 'danger' : null],
                ['label' => 'Memory', 'value' => $memory !== null && ! is_nan($memory) ? Format::percent($memory) : '—', 'tone' => $memory !== null && $memory > 0.9 ? 'danger' : 'dim'],
                ['label' => 'Load 1m', 'value' => $load !== null && ! is_nan($load) ? rtrim(rtrim(number_format($load, 2), '0'), '.') : '—', 'tone' => 'dim'],
                ['label' => 'Requests', 'value' => $requests !== null && ! is_nan($requests) ? Format::count($requests) : '—', 'tone' => 'dim'],
            ],
        ]);
    }
}
