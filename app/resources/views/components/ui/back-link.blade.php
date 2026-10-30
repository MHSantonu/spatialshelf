@props(['href', 'label'])

<a href="{{ $href }}" {{ $attributes->merge(['class' => 'inline-flex min-h-[44px] items-center gap-1 text-base font-semibold text-leaf-700 hover:text-leaf-800']) }}>
    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.2" stroke="currentColor" class="size-4" aria-hidden="true">
        <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5 8.25 12l7.5-7.5" />
    </svg>
    {{ $label }}
</a>
