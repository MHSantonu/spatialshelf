<div>
    <x-ui.page-heading title="Scan a shelf" sub="Film one shelf row, left to right, 10–15 seconds, close enough to read labels." />

    <div class="mt-4 grid gap-2">
        <label class="btn btn-primary btn-lg w-full cursor-pointer">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="size-6" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M6.827 6.175A2.31 2.31 0 0 1 5.186 7.23c-.38.054-.757.112-1.134.175C2.999 7.58 2.25 8.507 2.25 9.574V18a2.25 2.25 0 0 0 2.25 2.25h15A2.25 2.25 0 0 0 21.75 18V9.574c0-1.067-.75-1.994-1.802-2.169a47.865 47.865 0 0 0-1.134-.175 2.31 2.31 0 0 1-1.64-1.055l-.822-1.316a2.192 2.192 0 0 0-1.736-1.039 48.774 48.774 0 0 0-5.232 0 2.192 2.192 0 0 0-1.736 1.039l-.821 1.316Z" />
                <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 12.75a4.5 4.5 0 1 1-9 0 4.5 4.5 0 0 1 9 0Z" />
            </svg>
            Record a shelf
            <input type="file" class="sr-only" wire:model="video" accept="video/*" capture="environment">
        </label>
        <label class="btn btn-secondary btn-lg w-full cursor-pointer">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="size-6" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="m2.25 15.75 5.159-5.159a2.25 2.25 0 0 1 3.182 0l5.159 5.159m-1.5-1.5 1.409-1.409a2.25 2.25 0 0 1 3.182 0l2.909 2.909m-18 3.75h16.5a1.5 1.5 0 0 0 1.5-1.5V6a1.5 1.5 0 0 0-1.5-1.5H3.75A1.5 1.5 0 0 0 2.25 6v12a1.5 1.5 0 0 0 1.5 1.5Zm10.5-11.25h.008v.008h-.008V8.25Zm.375 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Z" />
            </svg>
            Choose from gallery
            <input type="file" class="sr-only" wire:model="video" accept="video/*">
        </label>
    </div>
    <div wire:loading wire:target="video" class="mt-3 flex items-center gap-2 text-base font-medium text-leaf-700">
        <span class="size-5 shrink-0 rounded-full border-2 border-stone-300 border-t-leaf-700 motion-safe:animate-spin" aria-hidden="true"></span>
        Uploading your clip…
    </div>
    @error('video')
        <p class="error-text">{{ $message }}</p>
    @enderror

    <x-ui.section-heading class="mt-8">Recent scans</x-ui.section-heading>
    @if ($scans->isEmpty())
        <x-ui.card class="mt-2">
            <x-ui.empty-state icon="scan" title="No scans yet" body="Film your first shelf to fill this list." />
        </x-ui.card>
    @else
        <ul class="mt-2 space-y-2">
            @foreach ($scans as $scan)
                <li>
                    <a href="{{ route('scans.show', $scan) }}" wire:navigate class="flex min-h-[64px] items-center justify-between gap-3 rounded-xl border border-stone-200 bg-white px-4 py-2.5 shadow-card">
                        <span class="text-base font-bold">Scan {{ $scan->id }}</span>
                        <span class="flex items-center gap-2">
                            <x-ui.badge :variant="match ($scan->status) {
                                \App\Enums\ScanStatus::Pending => 'neutral',
                                \App\Enums\ScanStatus::Processing => 'warn',
                                \App\Enums\ScanStatus::Completed => 'ok',
                                \App\Enums\ScanStatus::Failed => 'bad',
                            }">{{ ucfirst($scan->status->value) }}</x-ui.badge>
                            <span class="text-base tabular-nums text-stone-500">{{ $scan->items_count }} items</span>
                        </span>
                    </a>
                </li>
            @endforeach
        </ul>
    @endif
</div>
