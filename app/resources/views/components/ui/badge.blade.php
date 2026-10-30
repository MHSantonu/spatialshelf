@props(['variant' => 'neutral'])

@php
$classes = match ($variant) {
    'known', 'new' => 'bg-leaf-700 text-white',
    'high', 'ok' => 'bg-leaf-100 text-leaf-800',
    'unclear', 'med', 'warn', 'estimate' => 'bg-honey-100 text-honey-800',
    'low', 'bad' => 'bg-red-100 text-red-800',
    default => 'bg-stone-100 text-stone-600',
};
@endphp

<span {{ $attributes->merge(['class' => 'inline-flex min-h-[24px] shrink-0 items-center whitespace-nowrap rounded-full px-2.5 py-0.5 text-xs font-bold '.$classes]) }}>{{ $slot }}</span>
