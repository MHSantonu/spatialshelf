<div @if(!$finished) wire:poll.2s @endif>
    <x-ui.back-link :href="route('scan')" label="Back to scan" wire:navigate />
    <div class="mt-1 flex items-baseline justify-between gap-2">
        <h1 class="text-xl font-bold text-stone-900">Scan {{ $scan->id }}</h1>
        <p class="text-base tabular-nums text-stone-500">{{ $elapsed }}s elapsed</p>
    </div>

    @if ($scan->status === \App\Enums\ScanStatus::Failed)
        <div class="mt-4 rounded-xl border border-red-200 bg-red-50 p-4 shadow-card">
            <p class="text-lg font-bold text-red-800">This scan failed</p>
            <p class="mt-1 text-base text-red-700">{{ $error ?? 'Something went wrong.' }}</p>
            <x-ui.button wire:click="retry" wire:loading.attr="disabled" class="mt-3">
                <span wire:loading.remove wire:target="retry">Retry scan</span>
                <span wire:loading wire:target="retry">Queuing…</span>
            </x-ui.button>
        </div>
    @else
        @if ($currentStep >= 1)
            <x-ui.keyframe-hero :scan="$scan" :step="$currentStep" />
        @endif

        <x-ui.phrase-chips :picked="$pickedPhrases" :source="$phraseSource" />

        @if ($scan->status === \App\Enums\ScanStatus::Completed)
            <div class="mt-4">
                <x-ui.progress-steps :steps="$steps" :current="$currentStep" :seconds="$stepSeconds" />
            </div>
            <x-ui.card class="mt-4">
                <p class="text-2xl font-bold text-stone-900">Found {{ $itemCount }} products · {{ $knownCount }} already known</p>
                @if ($knownCount > 0)
                    <p class="mt-1 flex items-center gap-2 text-base text-stone-600">
                        <x-ui.badge variant="known">Known</x-ui.badge>
                        <span>{{ $knownCount }} recognised from memory — no AI needed</span>
                    </p>
                @endif
                @if ($memorySeconds !== null && $readSeconds !== null)
                    <p class="mt-1 text-base tabular-nums text-stone-500">Memory check {{ $memorySeconds }}s · Reading labels {{ $readSeconds }}s</p>
                @endif
                <div class="mt-3 grid {{ $hasOverlay ? 'grid-cols-2' : 'grid-cols-1' }} gap-2">
                    <x-ui.button :href="route('scans.review', $scan)" wire:navigate>Review products</x-ui.button>
                    @if ($hasOverlay)
                        <x-ui.button :href="route('scans.watch', $scan)" variant="secondary">Watch scan</x-ui.button>
                    @endif
                </div>
            </x-ui.card>
        @else
            @if ($currentStep < 0)
                <p class="mt-4 text-base text-stone-600">Queued — the scan starts in a moment.</p>
            @endif
            <div class="mt-4">
                <x-ui.progress-steps :steps="$steps" :current="$currentStep" :seconds="$stepSeconds" />
            </div>
        @endif
    @endif
</div>
