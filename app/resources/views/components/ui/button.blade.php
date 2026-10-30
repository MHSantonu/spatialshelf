@props(['variant' => 'primary', 'href' => null, 'size' => null])

@php
$classes = 'btn '.match ($variant) {
    'primary' => 'btn-primary',
    'secondary' => 'btn-secondary',
    'ghost' => 'btn-ghost',
    default => 'btn-primary',
}.($size === 'lg' ? ' btn-lg' : '');
@endphp

@if ($href)
    <a href="{{ $href }}" {{ $attributes->merge(['class' => $classes]) }}>{{ $slot }}</a>
@else
    <button {{ $attributes->merge(['class' => $classes, 'type' => 'button']) }}>{{ $slot }}</button>
@endif
