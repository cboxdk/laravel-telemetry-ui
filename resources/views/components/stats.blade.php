@props(['items' => []])

{{-- items: list of ['label' => ..., 'value' => ..., 'tone' => null|'ok'|'warn'|'danger'|'dim'] --}}
<div class="tui-stats">
    @foreach ($items as $item)
        <div class="tui-stat">
            <span class="tui-stat-label">{{ $item['label'] }}</span>
            <span class="tui-stat-value tui-tone-{{ $item['tone'] ?? 'default' }}">{{ $item['value'] }}</span>
        </div>
    @endforeach
</div>
