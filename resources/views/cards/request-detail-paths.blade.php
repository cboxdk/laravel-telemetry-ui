<x-telemetry-ui::card title="Paths" subtitle="Concrete URLs behind this route pattern — click one for its traces" span="2">
    @if ($error)
        <div class="tui-error">{{ $error }}</div>
    @elseif ($paths === [])
        <div class="tui-empty">No concrete paths recorded for this route in this period.</div>
    @else
        <div class="tui-table-wrap">
            <table class="tui-table">
                <tbody>
                    @foreach ($paths as $path)
                        <tr data-row-href="{{ $this->tracesUrl($path) }}">
                            <td class="is-primary is-wide"><a href="{{ $this->tracesUrl($path) }}" title="Traces for this path">{{ $path }}</a></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</x-telemetry-ui::card>
