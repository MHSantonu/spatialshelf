@props(['pad' => true])

<div {{ $attributes->merge(['class' => 'rounded-xl border border-stone-200 bg-white shadow-card'.($pad ? ' p-4' : '')]) }}>
    {{ $slot }}
</div>
