<x-telemetry-ui::card title="Issues" :subtitle="$label !== '' ? $label : 'Open issues and pull requests from your tracker'" span="2">
    <x-slot:actions>
        @if ($url !== '')
            <a class="tui-btn" href="{{ $url }}" target="_blank" rel="noopener">View all ↗</a>
        @endif
    </x-slot:actions>

    @if ($error)
        <div class="tui-error">{{ $error }}</div>
    @else
        <div class="tui-toolbar">
            <select class="tui-input" style="min-width: 110px" wire:model.live="state">
                <option value="open">Open</option>
                <option value="closed">Closed</option>
                <option value="all">All</option>
            </select>
            <input type="search" class="tui-input tui-input-grow" placeholder="Search titles…" wire:model.live.debounce.400ms="search">
        </div>

        @if ($issues === [])
            <div class="tui-empty">No {{ $state ?? 'open' }} issues. 🎉</div>
        @else
            <div class="tui-table-wrap">
                <table class="tui-table">
                    <thead>
                        <tr>
                            <th></th>
                            <th>Title</th>
                            <th>Labels</th>
                            <th>Author</th>
                            <th class="is-num">Comments</th>
                            <th class="is-num">Updated</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($issues as $issue)
                            <tr>
                                <td>
                                    <span class="tui-badge {{ $issue->kind === 'pr' ? 'tui-badge-info' : ($issue->isOpen() ? 'tui-badge-ok' : '') }}">
                                        {{ $issue->kind === 'pr' ? 'PR' : $issue->id }}
                                    </span>
                                </td>
                                <td class="is-primary is-wide"><a href="{{ $issue->url }}" target="_blank" rel="noopener">{{ $issue->title }}</a></td>
                                <td>
                                    @foreach (array_slice($issue->labels, 0, 4) as $lbl)
                                        <span class="tui-badge">{{ $lbl }}</span>
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
