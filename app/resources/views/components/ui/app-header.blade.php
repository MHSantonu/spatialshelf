@props(['shopName'])

<header class="sticky top-0 z-10 border-b border-stone-200 bg-white/95 backdrop-blur">
    <div class="flex min-h-[64px] items-center gap-3 px-4">
        <span class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-leaf-700 text-white" aria-hidden="true">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="size-6">
                <path stroke-linecap="round" stroke-linejoin="round" d="M5 4v16M19 4v16M5 9.5h14M5 15.5h14" />
            </svg>
        </span>
        <span class="min-w-0 flex-1 leading-tight">
            <span class="block text-xs font-bold uppercase tracking-wider text-leaf-700">SpatialShelf</span>
            <span class="block truncate text-lg font-bold text-stone-900">{{ $shopName }}</span>
        </span>
        {{ $slot }}
    </div>
</header>
