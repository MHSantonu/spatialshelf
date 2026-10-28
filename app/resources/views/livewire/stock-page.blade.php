<div>
    <h1 class="text-xl font-bold">Stock</h1>
    <p class="mt-1 text-sm text-zinc-600">{{ $totalProducts }} products · {{ \App\Livewire\StockPage::money($totalValue) }} stock value</p>

    <input type="search" wire:model.live.debounce.250ms="search" placeholder="Search products…" aria-label="Search products"
        class="mt-3 flex min-h-[48px] w-full items-center rounded-xl border border-zinc-300 px-3 focus:border-emerald-600 focus:outline-none">

    @forelse ($groups as $row => $products)
        <h2 class="mt-5 text-sm font-semibold text-zinc-500">{{ $row === 0 ? 'No shelf row' : 'Row '.$row }}</h2>
        <ul class="mt-1 divide-y divide-zinc-200 rounded-xl border border-zinc-200">
            @foreach ($products as $product)
                <li>
                    <button type="button" wire:click="startEdit({{ $product->id }})" class="flex min-h-[72px] w-full items-center gap-3 px-3 py-2 text-left focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-emerald-600">
                        @php($thumb = $product->images->sortByDesc('id')->first())
                        @if ($thumb)
                            <img src="{{ route('product-images.show', $thumb) }}" alt="" class="size-14 shrink-0 rounded-lg bg-zinc-100 object-cover">
                        @else
                            <span class="flex size-14 shrink-0 items-center justify-center rounded-lg bg-zinc-100 text-xl font-bold text-zinc-400" aria-hidden="true">{{ mb_substr($product->name, 0, 1) }}</span>
                        @endif
                        <span class="min-w-0 flex-1">
                            <span class="block truncate font-semibold">{{ $product->name }}</span>
                            <span class="block text-sm text-zinc-500">{{ \App\Livewire\StockPage::sizeFor($product) }}</span>
                        </span>
                        <span class="shrink-0 text-right">
                            <span class="block font-semibold">{{ \App\Livewire\StockPage::money((float) $product->price) }}</span>
                            <span class="block text-sm tabular-nums text-zinc-500">{{ $product->stock_is_estimate ? '~' : '' }}{{ $product->stock_qty }}</span>
                        </span>
                    </button>
                    @if ($editingId === $product->id)
                        <div class="border-t border-zinc-100 px-3 py-3">
                            <div class="grid grid-cols-2 gap-2">
                                <div>
                                    <label for="stock-price-{{ $product->id }}" class="text-sm font-medium">Price (৳)</label>
                                    <input id="stock-price-{{ $product->id }}" type="number" min="0" step="0.01" inputmode="decimal" wire:model="editPrice" class="mt-1 flex min-h-[48px] w-full items-center rounded-xl border border-zinc-300 px-3 focus:border-emerald-600 focus:outline-none">
                                    @error('editPrice') <p class="mt-1 text-sm font-medium text-red-700">{{ $message }}</p> @enderror
                                </div>
                                <div>
                                    <label for="stock-qty-{{ $product->id }}" class="text-sm font-medium">Qty</label>
                                    <input id="stock-qty-{{ $product->id }}" type="number" min="0" step="1" inputmode="numeric" wire:model="editQty" class="mt-1 flex min-h-[48px] w-full items-center rounded-xl border border-zinc-300 px-3 focus:border-emerald-600 focus:outline-none">
                                    @error('editQty') <p class="mt-1 text-sm font-medium text-red-700">{{ $message }}</p> @enderror
                                </div>
                            </div>
                            <div class="mt-2 grid grid-cols-2 gap-2">
                                <button type="button" wire:click="cancelEdit" class="inline-flex min-h-[44px] items-center justify-center rounded-xl border border-zinc-300 font-semibold hover:bg-zinc-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-600">Cancel</button>
                                <button type="button" wire:click="saveEdit" class="inline-flex min-h-[44px] items-center justify-center rounded-xl bg-emerald-600 font-semibold text-white hover:bg-emerald-700 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-600">Save</button>
                            </div>
                        </div>
                    @endif
                </li>
            @endforeach
        </ul>
    @empty
        <p class="mt-4 text-sm text-zinc-600">No products found.</p>
    @endforelse
</div>
