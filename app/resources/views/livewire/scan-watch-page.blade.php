<div
    id="scan-overlay-root"
    data-clip-url="{{ route('scans.clip', $scan) }}"
    data-sam-url="{{ route('scans.sam', [$scan, 'overlay_'.$overlaySlug]) }}"
    data-phrase="{{ str_replace('_', ' ', $overlaySlug) }}"
>
    <x-ui.back-link :href="route('scans.show', $scan)" :label="'Back to scan '.$scan->id" wire:navigate />
    <h1 class="mt-1 text-xl font-bold text-stone-900">Watch scan</h1>
    <p class="mt-1 text-base text-stone-600">Tracking: {{ str_replace('_', ' ', $overlaySlug) }}</p>

    <div class="mt-3 rounded-xl border border-stone-200 bg-black p-1 shadow-card">
        <canvas id="scan-overlay-canvas" class="w-full rounded-lg"></canvas>
    </div>

    <div class="mt-3 flex items-center gap-4">
        <x-ui.button id="scan-overlay-play" disabled>Play</x-ui.button>
        <label class="inline-flex min-h-[48px] cursor-pointer items-center gap-2 text-base font-medium text-stone-800">
            <input type="checkbox" id="scan-overlay-loop" checked class="size-5 accent-leaf-700">
            Loop
        </label>
    </div>
    <p id="scan-overlay-status" role="status" class="mt-2 text-base text-stone-600">Loading clip and overlay…</p>

    @vite('resources/js/scan-overlay.ts')
</div>
