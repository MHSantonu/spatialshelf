@props(['variant' => 'rows', 'count' => 3])

<div role="status" aria-label="Loading">
    <span class="sr-only">Loading…</span>
    @if ($variant === 'card')
        <div class="rounded-xl border border-stone-200 bg-white p-3 shadow-card" aria-hidden="true">
            <div class="skeleton h-64 w-full rounded-xl"></div>
            <div class="skeleton mt-3 h-5 w-2/3 rounded-md"></div>
            <div class="skeleton mt-2 h-4 w-1/2 rounded-md"></div>
        </div>
    @else
        <div class="space-y-2" aria-hidden="true">
            @for ($i = 0; $i < $count; $i++)
                <div class="skeleton h-[72px] rounded-xl"></div>
            @endfor
        </div>
    @endif
</div>
