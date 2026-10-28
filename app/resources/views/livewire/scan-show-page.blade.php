<div @if(!$finished) wire:poll.2s @endif>
    <a href="{{ route('scan') }}" wire:navigate class="inline-flex min-h-[44px] items-center text-sm font-medium text-emerald-700 hover:text-emerald-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-600">
        ← Back to scan
    </a>
    <div class="mt-1 flex items-baseline justify-between gap-2">
        <h1 class="text-xl font-bold">Scan {{ $scan->id }}</h1>
        <p class="text-sm tabular-nums text-zinc-500">{{ $elapsed }}s elapsed</p>
    </div>

    @if ($scan->status === \App\Enums\ScanStatus::Failed)
        <div class="mt-4 rounded-xl border border-red-200 bg-red-50 p-4">
            <p class="font-semibold text-red-800">This scan failed</p>
            <p class="mt-1 text-sm text-red-700">{{ $error ?? 'Something went wrong.' }}</p>
            <button type="button" wire:click="retry" wire:loading.attr="disabled" class="mt-3 inline-flex min-h-[44px] items-center rounded-xl bg-emerald-600 px-6 font-semibold text-white hover:bg-emerald-700 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-600 disabled:opacity-50">
                <span wire:loading.remove wire:target="retry">Retry scan</span>
                <span wire:loading wire:target="retry">Queuing…</span>
            </button>
        </div>
    @elseif ($scan->status === \App\Enums\ScanStatus::Completed)
        <div class="mt-4 rounded-xl border border-zinc-200 p-4">
            <p class="font-semibold">Found {{ $itemCount }} products · {{ $knownCount }} already known</p>
            <div class="mt-3 grid {{ $hasOverlay ? 'grid-cols-2' : 'grid-cols-1' }} gap-2">
                <a href="{{ route('scans.review', $scan) }}" wire:navigate class="inline-flex min-h-[48px] items-center justify-center rounded-xl bg-emerald-600 px-4 font-semibold text-white hover:bg-emerald-700 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-600">Review products</a>
                @if ($hasOverlay)
                    <a href="{{ route('scans.watch', $scan) }}" class="inline-flex min-h-[48px] items-center justify-center rounded-xl border border-zinc-300 px-4 font-semibold text-zinc-800 hover:bg-zinc-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-600">Watch scan</a>
                @endif
            </div>
        </div>
    @else
        @if ($currentStep < 0)
            <p class="mt-4 text-sm text-zinc-600">Queued — the scan starts in a moment.</p>
        @endif
        <ol class="mt-4 space-y-1">
            @foreach ($steps as $index => $step)
                <li class="flex min-h-[44px] items-center gap-3 rounded-lg px-2 {{ $index === $currentStep ? 'bg-emerald-50' : '' }}">
                    @if ($index < $currentStep || $currentStep >= count($steps))
                        <span class="flex size-6 shrink-0 items-center justify-center rounded-full bg-emerald-600 text-white" aria-hidden="true">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="3" stroke="currentColor" class="size-3.5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" />
                            </svg>
                        </span>
                    @elseif ($index === $currentStep)
                        <span class="flex size-6 shrink-0 items-center justify-center" aria-hidden="true">
                            <span class="size-3 rounded-full bg-emerald-600 motion-safe:animate-pulse"></span>
                        </span>
                    @else
                        <span class="flex size-6 shrink-0 items-center justify-center" aria-hidden="true">
                            <span class="size-3 rounded-full border-2 border-zinc-300"></span>
                        </span>
                    @endif
                    <span @class(['text-[15px]', 'font-semibold text-zinc-900' => $index === $currentStep, 'text-zinc-500' => $index !== $currentStep])>{{ $step }}</span>
                </li>
            @endforeach
        </ol>
        @if ($phrases !== [])
            <p class="mt-4 text-sm text-zinc-600">Looking for: {{ implode(', ', $phrases) }}</p>
        @endif
    @endif
</div>
