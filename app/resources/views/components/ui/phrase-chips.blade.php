@props(['picked' => [], 'source' => 'picked'])

@if ($picked !== [])
    <div class="mt-4">
        <p class="text-base font-semibold text-stone-800">{{ $source === 'picked' ? 'Muse Spark chose:' : 'Searching for:' }}</p>
        <div class="mt-2 flex flex-wrap gap-2">
            @foreach ($picked as $i => $phrase)
                <span class="chip-pop inline-flex min-h-[40px] items-center rounded-full bg-leaf-700 px-4 text-base font-semibold text-white" style="animation-delay: {{ $i * 140 }}ms">{{ $phrase }}</span>
            @endforeach
        </div>
        <p class="mt-2 text-sm text-stone-500">Safety net: {{ implode(' · ', \App\Livewire\ScanShowPage::BASELINE_PHRASES) }}</p>
    </div>
@endif
