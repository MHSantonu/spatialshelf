<div>
    <a href="{{ route('scans.show', $scan) }}" wire:navigate class="inline-flex min-h-[44px] items-center text-sm font-medium text-emerald-700 hover:text-emerald-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-600">
        ← Back to scan {{ $scan->id }}
    </a>

    @if ($current === null)
        <h1 class="mt-1 text-xl font-bold">All done.</h1>
        <p class="mt-2 text-zinc-600">Every product in this scan has been reviewed.</p>
        <a href="{{ route('stock') }}" wire:navigate class="mt-4 inline-flex min-h-[48px] items-center rounded-xl bg-emerald-600 px-6 font-semibold text-white hover:bg-emerald-700 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-600">Go to Stock</a>
    @else
        <div class="mt-1 flex items-center justify-between gap-2">
            <h1 class="text-xl font-bold">Review products</h1>
            <p class="text-sm tabular-nums text-zinc-500">{{ $position }} / {{ $total }}</p>
        </div>
        @if ($unclearRemaining > 0)
            <button type="button" wire:click="rejectUnclear" wire:confirm="Reject {{ $unclearRemaining }} unclear cards?" class="mt-2 inline-flex min-h-[44px] items-center text-sm font-medium text-zinc-600 underline underline-offset-2 hover:text-zinc-900 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-600">
                Reject all unclear ({{ $unclearRemaining }})
            </button>
        @endif

        <div
            x-data="{ startX: 0, dx: 0, dragging: false }"
            @pointerdown="dragging = true; startX = $event.clientX"
            @pointermove.window="if (dragging) dx = Math.max(-160, Math.min(160, $event.clientX - startX))"
            @pointerup.window="dragging = false; if (dx > 100) $wire.confirm(); else if (dx < -100) $wire.reject(); dx = 0"
            @pointercancel.window="dragging = false; dx = 0"
            :style="`transform: translateX(${dx}px) rotate(${dx / 20}deg); transition: ${dragging ? 'none' : 'transform 150ms'}`"
            class="relative mt-3 touch-pan-y select-none rounded-2xl border border-zinc-200 bg-white p-3 shadow-sm"
        >
            <div class="pointer-events-none absolute inset-x-0 top-0 z-10 flex items-start justify-between p-5">
                <span :style="`opacity: ${Math.max(0, Math.min(1, -dx / 100))}`" class="flex size-12 items-center justify-center rounded-full bg-red-600 text-2xl font-bold text-white" aria-hidden="true">✕</span>
                <span :style="`opacity: ${Math.max(0, Math.min(1, dx / 100))}`" class="flex size-12 items-center justify-center rounded-full bg-emerald-600 text-2xl font-bold text-white" aria-hidden="true">✓</span>
            </div>

            <img src="{{ route('scans.crop', [$scan, basename($current->crop_path)]) }}" alt="{{ $shown['name'] }}" class="h-64 w-full rounded-xl bg-zinc-100 object-cover" draggable="false">

            <div class="mt-3 flex items-start justify-between gap-2">
                <button type="button" wire:click="startEdit" class="min-h-[44px] text-left text-lg font-bold hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-600">
                    {{ $shown['name'] }}
                </button>
                <span class="flex shrink-0 items-center gap-2 pt-2">
                    @if ($current->matched_product_id !== null)
                        <span class="rounded-full bg-emerald-100 px-2.5 py-0.5 text-xs font-semibold text-emerald-800">Known</span>
                    @endif
                    @php($confidence = (float) (($current->extracted ?? [])['confidence'] ?? 0))
                    <span title="Confidence {{ number_format($confidence, 2) }}" @class([
                        'size-3 rounded-full',
                        'bg-emerald-500' => $confidence >= 0.8,
                        'bg-amber-400' => $confidence >= 0.5 && $confidence < 0.8,
                        'bg-red-500' => $confidence < 0.5,
                    ])></span>
                </span>
            </div>

            <p class="mt-1 text-sm text-zinc-600">
                @php($sizeValue = $shown['size_value'])
                {{ (is_numeric($sizeValue) && $sizeValue > 0 ? rtrim(rtrim(sprintf('%.2f', (float) $sizeValue), '0'), '.').' '.$shown['size_unit'] : 'size unknown') }}
                · {{ ucfirst(str_replace('_', ' ', $shown['category'])) }}
                · ~{{ $shown['qty'] }} in stock
            </p>

            @if ($nearest !== null)
                <div class="mt-2 flex items-center justify-between gap-2 rounded-lg bg-amber-50 px-3 py-2 text-sm">
                    <p class="text-amber-900">Might be a duplicate of <span class="font-semibold">{{ ($nearest->extracted ?? [])['name'] ?? 'another card' }}</span></p>
                    <button type="button" wire:click="mergeWith({{ $nearest->id }})" class="inline-flex min-h-[44px] shrink-0 items-center rounded-lg bg-amber-600 px-3 font-semibold text-white hover:bg-amber-700 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-amber-600">Merge</button>
                </div>
            @endif

            <div class="mt-3">
                <label for="review-price" class="text-sm font-medium">Price (৳)</label>
                <input id="review-price" type="number" min="0" step="0.01" inputmode="decimal" wire:model.live="price" class="mt-1 flex min-h-[48px] w-full items-center rounded-xl border border-zinc-300 px-3 text-lg focus:border-emerald-600 focus:outline-none">
                @error('price')
                    <p class="mt-1 text-sm font-medium text-red-700">{{ $message }}</p>
                @enderror
            </div>

            <div class="mt-3 grid grid-cols-3 gap-2">
                <button type="button" wire:click="reject" wire:loading.attr="disabled" class="inline-flex min-h-[56px] items-center justify-center rounded-xl border border-zinc-300 text-2xl font-bold text-red-600 hover:bg-red-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-red-600 disabled:opacity-50" aria-label="Reject">✕</button>
                <button type="button" wire:click="confirm" wire:loading.attr="disabled" @disabled(! is_numeric($price) || (float) $price <= 0) class="inline-flex min-h-[56px] items-center justify-center rounded-xl bg-emerald-600 text-2xl font-bold text-white hover:bg-emerald-700 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-600 disabled:opacity-40" aria-label="Confirm">✓</button>
                <button type="button" wire:click="startPick" class="inline-flex min-h-[56px] items-center justify-center rounded-xl border border-zinc-300 px-2 text-[15px] font-semibold text-zinc-800 hover:bg-zinc-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-600">Same as…</button>
            </div>
        </div>
    @endif

    @if ($editing)
        <div class="fixed inset-0 z-50 flex items-end justify-center bg-black/50 p-4" role="dialog" aria-modal="true" aria-label="Edit product">
            <div class="w-full max-w-[440px] rounded-2xl bg-white p-4">
                <h2 class="text-lg font-bold">Edit product</h2>
                <div class="mt-3 space-y-3">
                    <div>
                        <label for="edit-name" class="text-sm font-medium">Name</label>
                        <input id="edit-name" type="text" wire:model="form.name" class="mt-1 flex min-h-[48px] w-full items-center rounded-xl border border-zinc-300 px-3 focus:border-emerald-600 focus:outline-none">
                        @error('form.name') <p class="mt-1 text-sm font-medium text-red-700">{{ $message }}</p> @enderror
                    </div>
                    <div class="grid grid-cols-2 gap-2">
                        <div>
                            <label for="edit-size-value" class="text-sm font-medium">Size</label>
                            <input id="edit-size-value" type="number" min="0" step="any" inputmode="decimal" wire:model="form.size_value" class="mt-1 flex min-h-[48px] w-full items-center rounded-xl border border-zinc-300 px-3 focus:border-emerald-600 focus:outline-none">
                            @error('form.size_value') <p class="mt-1 text-sm font-medium text-red-700">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label for="edit-size-unit" class="text-sm font-medium">Unit</label>
                            <select id="edit-size-unit" wire:model="form.size_unit" class="mt-1 flex min-h-[48px] w-full items-center rounded-xl border border-zinc-300 bg-white px-3 focus:border-emerald-600 focus:outline-none">
                                @foreach (\App\Livewire\ScanReviewPage::SIZE_UNITS as $unit)
                                    <option value="{{ $unit }}">{{ $unit }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                    <div class="grid grid-cols-2 gap-2">
                        <div>
                            <label for="edit-category" class="text-sm font-medium">Category</label>
                            <select id="edit-category" wire:model="form.category" class="mt-1 flex min-h-[48px] w-full items-center rounded-xl border border-zinc-300 bg-white px-3 focus:border-emerald-600 focus:outline-none">
                                @foreach (\App\Livewire\ScanReviewPage::CATEGORIES as $category)
                                    <option value="{{ $category }}">{{ ucfirst(str_replace('_', ' ', $category)) }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label for="edit-qty" class="text-sm font-medium">Est. stock</label>
                            <input id="edit-qty" type="number" min="0" step="1" inputmode="numeric" wire:model="form.qty" class="mt-1 flex min-h-[48px] w-full items-center rounded-xl border border-zinc-300 px-3 focus:border-emerald-600 focus:outline-none">
                            @error('form.qty') <p class="mt-1 text-sm font-medium text-red-700">{{ $message }}</p> @enderror
                        </div>
                    </div>
                </div>
                <div class="mt-4 grid grid-cols-2 gap-2">
                    <button type="button" wire:click="cancelEdit" class="inline-flex min-h-[48px] items-center justify-center rounded-xl border border-zinc-300 font-semibold hover:bg-zinc-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-600">Cancel</button>
                    <button type="button" wire:click="saveEdit" class="inline-flex min-h-[48px] items-center justify-center rounded-xl bg-emerald-600 font-semibold text-white hover:bg-emerald-700 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-600">Save</button>
                </div>
            </div>
        </div>
    @endif

    @if ($picking)
        <div class="fixed inset-0 z-50 flex items-end justify-center bg-black/50 p-4" role="dialog" aria-modal="true" aria-label="Pick the same product">
            <div class="max-h-[80dvh] w-full max-w-[440px] overflow-y-auto rounded-2xl bg-white p-4">
                <div class="flex items-center justify-between gap-2">
                    <h2 class="text-lg font-bold">Same as…</h2>
                    <button type="button" wire:click="cancelPick" class="inline-flex min-h-[44px] min-w-[44px] items-center justify-center rounded-lg text-zinc-600 hover:text-zinc-900 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-600" aria-label="Close">✕</button>
                </div>
                <ul class="mt-2 space-y-2">
                    @forelse ($others as $other)
                        <li>
                            <button type="button" wire:click="mergeWith({{ $other->id }})" class="flex min-h-[64px] w-full items-center gap-3 rounded-xl border border-zinc-200 p-2 text-left hover:bg-zinc-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-600">
                                <img src="{{ route('scans.crop', [$scan, basename($other->crop_path)]) }}" alt="" class="size-12 shrink-0 rounded-lg bg-zinc-100 object-cover">
                                <span class="min-w-0">
                                    <span class="block truncate font-semibold">{{ ($other->extracted ?? [])['name'] ?? 'Unnamed product' }}</span>
                                    <span class="block text-sm text-zinc-500">Row {{ $other->shelf_row }}{{ $other->shelf_row === $current?->shelf_row ? ' · same row' : '' }}</span>
                                </span>
                            </button>
                        </li>
                    @empty
                        <li class="py-4 text-center text-sm text-zinc-500">No other cards in this scan.</li>
                    @endforelse
                </ul>
            </div>
        </div>
    @endif
</div>
