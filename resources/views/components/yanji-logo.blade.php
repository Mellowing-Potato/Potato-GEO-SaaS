@props(['variant' => 'compact', 'label' => '言及'])
@php
    $assets = [
        'compact' => ['yanji-compact.svg', 518, 180],
        'horizontal' => ['yanji-horizontal.svg', 704, 260],
        'symbol' => ['yanji-symbol.svg', 256, 260],
    ];
    [$filename, $width, $height] = $assets[$variant] ?? $assets['compact'];
@endphp
<img {{ $attributes->class(['yanji-logo'])->merge([
    'src' => '/brand/yanji/v1/'.$filename,
    'alt' => $label,
    'width' => $width,
    'height' => $height,
    'decoding' => 'async',
]) }}>
