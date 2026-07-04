@use('Cbox\TelemetryUi\Support\Format')

<x-telemetry-ui::card title="Exceptions by class" span="2">
    <x-slot:actions>
        <a class="tui-btn" href="{{ $errorTracesUrl }}">View error traces →</a>
    </x-slot:actions>

    @if ($error)
        <div class="tui-error">{{ $error }}</div>
    @elseif ($rows === [])
        <div class="tui-empty">No exceptions reported in this period. 🎉</div>
    @else
        <div class="tui-table-wrap">
            <table class="tui-table">
                <thead>
                    <tr>
                        <th>Exception</th>
                        <th class="is-num">Count</th>
                        @if ($hasIssues)<th class="is-num">Tracker</th>@endif
                        @if ($canCreate)<th class="is-num">Action</th>@endif
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rows as $row)
                        <tr data-row-href="{{ $this->detailUrl($row['exception']) }}">
                            <td class="is-primary is-wide">{{ $row['exception'] }}</td>
                            <td class="is-num tui-tone-danger">{{ Format::count($row['count']) }}</td>
                            @if ($hasIssues)
                                <td class="is-num"><a href="{{ $this->issuesUrl($row['exception']) }}" title="Find matching issues">⧉ issues</a></td>
                            @endif
                            @if ($canCreate)
                                @php($draft = $this->ticketDraft($row['exception'], $row['count']))
                                <td class="is-num">
                                    <button type="button" class="tui-btn tui-btn-sm" x-data
                                            x-on:click="window.Livewire.dispatch('telemetry-ui:compose-ticket', @js($draft))"
                                            title="Create a ticket prefilled with this exception">+ ticket</button>
                                </td>
                            @endif
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</x-telemetry-ui::card>
