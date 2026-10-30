@props(['scan', 'step' => 0])

{{-- Keyed by status + step so the image retries as the scan progresses;
     hidden entirely when no keyframe exists yet (early steps). --}}
<div wire:key="hero-{{ $scan->id }}-{{ $scan->status->value }}-{{ $step }}" data-hero
     class="mt-3 overflow-hidden rounded-xl border border-stone-200 shadow-card">
    <div class="skeleton aspect-video w-full bg-stone-100">
        <img src="{{ route('scans.keyframe', $scan) }}" alt="Middle frame of scan {{ $scan->id }}"
            onload="this.parentElement.classList.remove('skeleton')"
            onerror="this.closest('[data-hero]').classList.add('hidden')"
            class="relative z-10 aspect-video w-full object-cover">
    </div>
</div>
