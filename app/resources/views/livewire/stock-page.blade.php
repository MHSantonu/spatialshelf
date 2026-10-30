<div
    x-data="{ flashId: null, expectSave: false }"
    x-init="if ($wire && $wire.$watch) { $wire.$watch('editingId', (value, old) => { if (value === null && old !== null && expectSave) { flashId = old; setTimeout(() => { if (flashId === old) flashId = null; }, 1700); } expectSave = false; }); }"
>
    <x-ui.page-heading title="Stock" />
    <p class="tnum mt-1 text-base text-stone-600">{{ $totalProducts }} products · {{ \App\Livewire\StockPage::money($totalValue) }} stock value</p>

    <input type="search" wire:model.live.debounce.250ms="search" placeholder="Search products…" aria-label="Search products"
        class="input mt-3">

    @forelse ($groups as $row => $products)
        <x-ui.section-heading class="mt-5">{{ $row === 0 ? 'No shelf row' : 'Row '.$row }}</x-ui.section-heading>
        <ul class="mt-2 space-y-2">
            @foreach ($products as $product)
                <li :class="flashId === {{ $product->id }} ? 'stock-flash' : ''" class="rounded-xl border border-stone-200 bg-white shadow-card">
                    <button type="button" wire:click="startEdit({{ $product->id }})" @click="expectSave = false" class="flex min-h-[76px] w-full items-center gap-3 rounded-xl px-3 py-2 text-left">
                        @php($thumb = $product->images->sortByDesc('id')->first())
                        @if ($thumb)
                            <img src="{{ route('product-images.show', $thumb) }}" alt="" class="size-14 shrink-0 rounded-lg bg-stone-100 object-cover" loading="lazy">
                        @else
                            <span class="flex size-14 shrink-0 items-center justify-center rounded-lg bg-leaf-50 text-xl font-bold text-leaf-700" aria-hidden="true">{{ mb_substr($product->name, 0, 1) }}</span>
                        @endif
                        <span class="min-w-0 flex-1">
                            <span class="block truncate text-base font-bold text-stone-900">{{ $product->name }}</span>
                            <span class="mt-0.5 block text-base text-stone-500">{{ \App\Livewire\StockPage::sizeFor($product) }}</span>
                        </span>
                        <span class="flex shrink-0 flex-col items-end gap-1">
                            <span class="price text-base text-stone-900">{{ \App\Livewire\StockPage::money((float) $product->price) }}</span>
                            <span class="flex items-center gap-1.5">
                                @if ($product->stock_qty <= 0)
                                    <x-ui.badge variant="bad">Out</x-ui.badge>
                                @elseif ($product->stock_qty <= 3)
                                    <x-ui.badge variant="warn">Low</x-ui.badge>
                                @endif
                                <span class="text-base tabular-nums text-stone-500" @if($product->stock_is_estimate) title="Estimated count" @endif>{{ $product->stock_is_estimate ? '~' : '' }}{{ $product->stock_qty }}</span>
                            </span>
                        </span>
                    </button>
                    @if ($editingId === $product->id)
                        <div class="border-t border-stone-100 px-3 py-3">
                            <div class="grid grid-cols-2 gap-2">
                                <div>
                                    <label for="stock-price-{{ $product->id }}" class="label">Price (৳)</label>
                                    <input id="stock-price-{{ $product->id }}" type="number" min="0" step="0.01" inputmode="decimal" wire:model="editPrice" class="input price mt-1">
                                    @error('editPrice') <p class="error-text">{{ $message }}</p> @enderror
                                </div>
                                <div>
                                    <label for="stock-qty-{{ $product->id }}" class="label">Qty</label>
                                    <input id="stock-qty-{{ $product->id }}" type="number" min="0" step="1" inputmode="numeric" wire:model="editQty" class="input tnum mt-1">
                                    @error('editQty') <p class="error-text">{{ $message }}</p> @enderror
                                </div>
                            </div>
                            <div class="mt-2 grid grid-cols-2 gap-2">
                                <x-ui.button variant="secondary" wire:click="cancelEdit" @click="expectSave = false">Cancel</x-ui.button>
                                <x-ui.button wire:click="saveEdit" @click="expectSave = true">Save</x-ui.button>
                            </div>
                        </div>
                    @endif
                </li>
            @endforeach
        </ul>
    @empty
        <x-ui.card class="mt-4" :pad="false">
            @if (trim($search) !== '')
                <x-ui.empty-state icon="search" title="No matches" body="Nothing in stock matches “{{ trim($search) }}”." />
            @else
                <x-ui.empty-state icon="box" title="Stock is empty" body="Scan a shelf and confirm products to fill it.">
                    <x-ui.button :href="route('scan')" wire:navigate>Scan a shelf</x-ui.button>
                </x-ui.empty-state>
            @endif
        </x-ui.card>
    @endforelse
</div>
