<x-telemetry-ui::card title="Actions & context" span="1">
    @if ($error)
        <div class="tui-error">{{ $error }}</div>
    @else
        @if ($canCreate && $draft !== null)
            <div class="tui-sidebar-actions">
                <button type="button" class="tui-btn tui-btn-primary" x-data
                        x-on:click="window.Livewire.dispatch('telemetry-ui:compose-ticket', @js($draft))">
                    + Create ticket
                </button>
            </div>
        @endif

        @if ($related !== [])
            <h3 class="tui-section-title" style="margin-top: 12px;">Related tickets</h3>
            <div class="tui-related-issues">
                @foreach ($related as $issue)
                    <a class="tui-related-issue tui-issue-link" data-issue-id="{{ $issue->id }}" href="{{ $issue->url }}">
                        <span class="tui-badge {{ $issue->isOpen() ? 'tui-badge-ok' : '' }}">{{ $issue->id }}</span>
                        <span class="tui-related-title">{{ Str::limit($issue->title, 60) }}</span>
                    </a>
                @endforeach
            </div>
        @elseif ($canCreate)
            <div class="tui-note" style="margin-top: 10px;">No tracker tickets mention this exception yet.</div>
        @endif

        @if ($stats !== null)
            <h3 class="tui-section-title">Facts</h3>
            <div class="tui-issue-facts" style="border: 0; padding-bottom: 0; flex-direction: column; align-items: flex-start; gap: 7px;">
                <span><em>first seen</em> {{ $stats['firstSeen'] }}</span>
                <span><em>last seen</em> {{ $stats['lastSeen'] }}</span>
                <span><em>source</em> {{ $stats['source'] }}</span>
                @if (($stats['users'] ?? 0) > 0)
                    <span><em>users affected</em> {{ $stats['users'] }}{{ $stats['sampled'] ? '+' : '' }}</span>
                @endif
                @if ($detail !== null && ($detail['environment'] ?? '') !== '')
                    <span><em>env</em> {{ $detail['environment'] }}</span>
                @endif
                @if ($detail !== null && ($detail['release'] ?? '') !== '')
                    <span><em>release</em> {{ $detail['release'] }}</span>
                @endif
                @if ($detail !== null && ($detail['host'] ?? '') !== '')
                    <span><em>host</em> <a class="tui-attr-filter" href="{{ route('telemetry-ui.page', ['page' => 'host-detail', 'host' => $detail['host']]) }}">{{ $detail['host'] }}</a></span>
                @endif
                @if ($detail !== null && ($detail['file'] ?? '') !== '')
                    <span><em>at</em> {{ $detail['file'] }}:{{ $detail['line'] }}</span>
                @endif
                <span><em>group</em> {{ $group }}</span>
            </div>
        @endif

        @if ($suspect !== null)
            <h3 class="tui-section-title">Suspect</h3>
            <div class="tui-suspect-row" style="font-size: 12px;">
                <span class="tui-annotation-dot" style="background: {{ $suspect['color'] }}"></span>
                <span><strong>{{ $suspect['label'] }}</strong> <span class="tui-tone-dim">— first seen {{ $suspect['gap'] }} later</span></span>
            </div>
        @endif
    @endif
</x-telemetry-ui::card>
