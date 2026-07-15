<x-telemetry-ui::card title="Issues" :subtitle="$multiSource ? 'Open issues and pull requests across '.count($sources).' repos' : 'Open issues and pull requests from your tracker'" span="2">
    <x-slot:actions>
        @if ($url !== '')
            <a class="tui-btn" href="{{ $url }}" target="_blank" rel="noopener">View all ↗</a>
        @endif
    </x-slot:actions>

    @if ($error && $rows === [])
        <div class="tui-error">{{ $error }}</div>
    @else
        @if ($error)
            {{-- Partial failure: some trackers returned issues, another is down. --}}
            <div class="tui-error" style="margin-bottom: .5rem">⚠ {{ $error }}</div>
        @endif
        <div class="tui-toolbar">
            <x-telemetry-ui::combobox class="tui-input" style="min-width: 100px" wire:model.live="state">
                <option value="open">Open</option>
                <option value="closed">Closed</option>
                <option value="all">All</option>
            </x-telemetry-ui::combobox>
            @if ($multiSource)
                <x-telemetry-ui::combobox class="tui-input" style="min-width: 120px" wire:model.live="sourceFilter">
                    <option value="">All repos</option>
                    @foreach ($sources as $src)
                        <option value="{{ $src }}">{{ $src }}</option>
                    @endforeach
                </x-telemetry-ui::combobox>
            @endif
            @if ($labels !== [])
                <x-telemetry-ui::combobox class="tui-input" style="min-width: 130px" wire:model.live="label">
                    <option value="">All labels</option>
                    @foreach ($labels as $lbl)
                        <option value="{{ $lbl }}">{{ $lbl }}</option>
                    @endforeach
                </x-telemetry-ui::combobox>
            @endif
            <input type="search" class="tui-input tui-input-grow" placeholder="Search titles…" wire:model.live.debounce.400ms="search">
        </div>

        @if ($rows === [])
            <div class="tui-empty">No matching issues. 🎉</div>
        @else
            <div class="tui-table-wrap">
                <table class="tui-table">
                    <thead>
                        <tr>
                            <th></th>
                            @if ($multiSource)<th>Repo</th>@endif
                            <th>Title</th>
                            <th>Labels</th>
                            <th>Author</th>
                            <th class="is-num">Comments</th>
                            <th class="is-num">Updated</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $row)
                            @php($issue = $row['issue'])
                            <tr>
                                <td>
                                    <span class="tui-badge {{ $issue->kind === 'pr' ? 'tui-badge-info' : ($issue->isOpen() ? 'tui-badge-ok' : '') }}">
                                        {{ $issue->kind === 'pr' ? 'PR' : $issue->id }}
                                    </span>
                                </td>
                                @if ($multiSource)<td><span class="tui-badge tui-badge-info">{{ $row['source'] }}</span></td>@endif
                                <td class="is-primary is-wide">
                                    {{-- The trace drawer resolves issues from the primary tracker, so only
                                         intercept for a single source; otherwise link straight to the tracker. --}}
                                    <a class="tui-issue-link" @unless($multiSource) data-issue-id="{{ $issue->id }}" @else target="_blank" rel="noopener" @endunless href="{{ $issue->url }}">{{ $issue->title }}</a>
                                </td>
                                <td>
                                    @foreach (array_slice($issue->labels, 0, 4) as $lbl)
                                        <button type="button" class="tui-badge tui-badge-clickable {{ $label === $lbl ? 'is-active' : '' }}" wire:click="filterLabel('{{ addslashes($lbl) }}')">{{ $lbl }}</button>
                                    @endforeach
                                </td>
                                <td>{{ $issue->author ?? '—' }}</td>
                                <td class="is-num">{{ $issue->count ?? '—' }}</td>
                                <td class="is-num">{{ $issue->updatedAt?->format('d/m H:i') ?? '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    @endif
</x-telemetry-ui::card>
