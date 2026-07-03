@props(['points' => [], 'width' => 84, 'height' => 22, 'color' => '#34d399'])

@php
    $values = array_values(array_filter($points, 'is_numeric'));
    $n = count($values);
    $max = $n ? max($values) : 0.0;
    $min = $n ? min($values) : 0.0;
    $span = $max - $min;

    $coords = [];
    foreach ($values as $i => $v) {
        $x = $n > 1 ? ($i / ($n - 1)) * $width : $width / 2;
        // Flat series sit mid-height; otherwise scale into a 2px-padded box.
        $y = $span > 0 ? $height - 2 - (($v - $min) / $span) * ($height - 4) : $height / 2;
        $coords[] = round($x, 1).','.round($y, 1);
    }
@endphp

@if ($n < 2)
    <span class="tui-spark-empty">—</span>
@else
    <svg class="tui-spark" viewBox="0 0 {{ $width }} {{ $height }}" width="{{ $width }}" height="{{ $height }}" preserveAspectRatio="none" aria-hidden="true">
        <polyline points="{{ implode(' ', $coords) }}" fill="none" stroke="{{ $color }}" stroke-width="1.25" stroke-linejoin="round" stroke-linecap="round" />
    </svg>
@endif
