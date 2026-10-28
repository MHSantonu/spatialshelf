<div
    id="scan-overlay-root"
    data-clip-url="{{ route('scans.clip', $scan) }}"
    data-sam-url="{{ route('scans.sam', [$scan, 'overlay_'.$overlaySlug]) }}"
    data-phrase="{{ str_replace('_', ' ', $overlaySlug) }}"
>
    <a href="{{ route('scans.show', $scan) }}" wire:navigate class="inline-flex min-h-[44px] items-center text-sm font-medium text-emerald-700 hover:text-emerald-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-600">
        ← Back to scan {{ $scan->id }}
    </a>
    <h1 class="mt-1 text-xl font-bold">Watch scan</h1>

    <canvas id="scan-overlay-canvas" class="mt-3 w-full rounded-xl bg-black"></canvas>

    <div class="mt-3 flex items-center gap-3">
        <button type="button" id="scan-overlay-play" disabled class="inline-flex min-h-[48px] items-center rounded-xl bg-emerald-600 px-6 font-semibold text-white hover:bg-emerald-700 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-600 disabled:opacity-50">
            Play
        </button>
        <label class="inline-flex min-h-[48px] cursor-pointer items-center gap-2 text-[15px] font-medium">
            <input type="checkbox" id="scan-overlay-loop" checked class="size-5 accent-emerald-600">
            Loop
        </label>
    </div>
    <p id="scan-overlay-status" role="status" class="mt-2 text-sm text-zinc-600">Loading clip and overlay…</p>

    @vite('resources/js/scan-overlay.ts')
</div>
