<form wire:submit="submitTicket" class="tui-compose">
    @if ($error)
        <div class="tui-error" style="text-align:left; padding: 10px 0;">{{ $error }}</div>
    @endif

    <label class="tui-compose-field">
        <span>Title</span>
        <input type="text" class="tui-input" wire:model="draftTitle" placeholder="Short summary" autofocus>
    </label>

    <label class="tui-compose-field">
        <span>Description</span>
        <textarea class="tui-input tui-compose-body" wire:model="draftBody" rows="14" spellcheck="false"></textarea>
    </label>

    @if ($draftLabels !== [])
        <div class="tui-compose-field">
            <span>Labels</span>
            <div>
                @foreach ($draftLabels as $lbl)
                    <span class="tui-badge">{{ $lbl }}</span>
                @endforeach
            </div>
        </div>
    @endif

    <div class="tui-compose-actions">
        <button type="button" class="tui-btn" wire:click="cancelCompose">Cancel</button>
        <button type="submit" class="tui-btn tui-btn-primary" wire:loading.attr="disabled" wire:target="submitTicket">
            <span wire:loading.remove wire:target="submitTicket">Create ticket</span>
            <span wire:loading wire:target="submitTicket">Creating…</span>
        </button>
    </div>
</form>
