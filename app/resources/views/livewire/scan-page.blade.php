<div>
    <h1 class="text-xl font-bold">Scan a shelf</h1>
    <p class="mt-1 text-sm text-zinc-600">Film one shelf row, left to right, 10–15 seconds, close enough to read labels.</p>

    <label class="mt-4 flex min-h-[64px] cursor-pointer items-center justify-center gap-2 rounded-xl bg-emerald-600 px-6 text-lg font-semibold text-white hover:bg-emerald-700 focus-within:outline-2 focus-within:outline-offset-2 focus-within:outline-emerald-600">
        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="size-6" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="M6.827 6.175A2.31 2.31 0 0 1 5.186 7.23c-.38.054-.757.112-1.134.175C2.999 7.58 2.25 8.507 2.25 9.574V18a2.25 2.25 0 0 0 2.25 2.25h15A2.25 2.25 0 0 0 21.75 18V9.574c0-1.067-.75-1.994-1.802-2.169a47.865 47.865 0 0 0-1.134-.175 2.31 2.31 0 0 1-1.64-1.055l-.822-1.316a2.192 2.192 0 0 0-1.736-1.039 48.774 48.774 0 0 0-5.232 0 2.192 2.192 0 0 0-1.736 1.039l-.821 1.316Z" />
            <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 12.75a4.5 4.5 0 1 1-9 0 4.5 4.5 0 0 1 9 0Z" />
        </svg>
        Scan a shelf
        <input type="file" class="sr-only" wire:model="video" accept="video/*" capture="environment">
    </label>
    <div wire:loading wire:target="video" class="mt-2 text-sm font-medium text-emerald-700">
        Uploading your clip…
    </div>
    @error('video')
        <p class="mt-2 text-sm font-medium text-red-700">{{ $message }}</p>
    @enderror

    <h2 class="mt-8 text-base font-semibold">Recent scans</h2>
    @if ($scans->isEmpty())
        <p class="mt-2 text-sm text-zinc-600">No scans yet. Your first scan will appear here.</p>
    @else
        <ul class="mt-2 divide-y divide-zinc-200 rounded-xl border border-zinc-200">
            @foreach ($scans as $scan)
                <li>
                    <a href="{{ route('scans.show', $scan) }}" wire:navigate class="flex min-h-[56px] items-center justify-between gap-3 px-4 py-2 focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-emerald-600">
                        <span class="font-medium">Scan {{ $scan->id }}</span>
                        <span class="flex items-center gap-2 text-sm">
                            <span @class([
                                'rounded-full px-2.5 py-0.5 text-xs font-semibold',
                                'bg-zinc-100 text-zinc-700' => $scan->status === \App\Enums\ScanStatus::Pending,
                                'bg-amber-100 text-amber-800' => $scan->status === \App\Enums\ScanStatus::Processing,
                                'bg-emerald-100 text-emerald-800' => $scan->status === \App\Enums\ScanStatus::Completed,
                                'bg-red-100 text-red-800' => $scan->status === \App\Enums\ScanStatus::Failed,
                            ])>{{ ucfirst($scan->status->value) }}</span>
                            <span class="text-zinc-500">{{ $scan->items_count }} items</span>
                        </span>
                    </a>
                </li>
            @endforeach
        </ul>
    @endif
</div>
