<div>
    <x-ui.back-link :href="route('orders')" label="Orders" wire:navigate />

    <div class="mt-1 flex items-center gap-2">
        <h1 class="text-xl font-bold tabular-nums text-stone-900">Order #{{ $order->id }}</h1>
        <x-ui.badge :variant="$order->status === \App\Enums\OrderStatus::Confirmed ? 'ok' : 'neutral'">{{ ucfirst($order->status->value) }}</x-ui.badge>
    </div>
    <p class="mt-1 text-base text-stone-500">
        {{ \App\Livewire\OrderDetailPage::customerName($order) }}
        · {{ $order->channel() === 'whatsapp' ? 'WhatsApp' : 'Try demo' }}
        · {{ $order->created_at?->format('j M Y, g:i a') }}
    </p>

    <x-ui.section-heading class="mt-5">Original input</x-ui.section-heading>
    <x-ui.card class="mt-2">
        @if ($order->source_type === 'photo' && $order->raw_input_path)
            <a href="{{ route('orders.input', $order) }}" target="_blank" rel="noopener" class="block rounded-lg">
                <img src="{{ route('orders.input', $order) }}" alt="Customer's handwritten list photo" class="max-h-64 w-full rounded-lg bg-stone-100 object-contain">
            </a>
            @if (\App\Livewire\OrderDetailPage::inputText($order))
                <p class="mt-2 text-base text-stone-800">{{ \App\Livewire\OrderDetailPage::inputText($order) }}</p>
            @endif
        @elseif ($order->source_type === 'voice' && $order->raw_input_path)
            <audio controls preload="metadata" src="{{ route('orders.input', $order) }}" class="w-full">Voice note</audio>
            @if ($order->transcript)
                <p class="mt-2 text-base text-stone-800">{{ $order->transcript }}</p>
            @endif
        @else
            <p class="text-base text-stone-800">{{ \App\Livewire\OrderDetailPage::inputText($order) ?? 'Original message not saved.' }}</p>
        @endif
    </x-ui.card>

    <div class="mt-5 flex items-baseline justify-between gap-2">
        <x-ui.section-heading>Picking route</x-ui.section-heading>
        @if ($route->isNotEmpty())
            <p class="text-base tabular-nums text-stone-500">{{ $pickedCount }} of {{ $route->count() }} picked</p>
        @endif
    </div>
    @if ($route->isEmpty())
        <x-ui.card class="mt-2">
            <x-ui.empty-state icon="search" title="Nothing to pick" body="No items matched the shelf." />
        </x-ui.card>
    @else
        <ul class="mt-2 space-y-2">
            @foreach ($route as $i => $line)
                <li class="rounded-xl border border-stone-200 bg-white shadow-card">
                    <label class="flex min-h-[76px] cursor-pointer items-center gap-3 rounded-xl px-3 py-2">
                        <span class="tnum flex size-7 shrink-0 items-center justify-center rounded-full bg-leaf-700 text-sm font-bold text-white" aria-hidden="true">{{ $i + 1 }}</span>
                        <input type="checkbox" wire:model="picked.{{ $line->id }}" value="1"
                            class="size-6 shrink-0 accent-leaf-700" aria-label="Picked {{ $line->product->name }}">
                        @php($thumb = $line->product->images->sortByDesc('id')->first())
                        @if ($thumb)
                            <img src="{{ route('product-images.show', $thumb) }}" alt="" class="size-14 shrink-0 rounded-lg bg-stone-100 object-cover" loading="lazy">
                        @else
                            <span class="flex size-14 shrink-0 items-center justify-center rounded-lg bg-leaf-50 text-xl font-bold text-leaf-700" aria-hidden="true">{{ mb_substr($line->product->name, 0, 1) }}</span>
                        @endif
                        <span class="min-w-0 flex-1">
                            <span class="block text-sm font-semibold tabular-nums uppercase tracking-wide text-leaf-700">{{ \App\Livewire\OrderDetailPage::shelfLabel($line->product) }}</span>
                            <span class="block truncate text-base font-bold text-stone-900">{{ $line->product->name }}</span>
                        </span>
                        <span class="shrink-0 text-right">
                            <span class="block text-base font-bold tabular-nums text-stone-900">×{{ $line->qty }}</span>
                            <span class="price block text-base text-stone-500">{{ \App\Livewire\OrderDetailPage::money((float) $line->line_total) }}</span>
                        </span>
                    </label>
                </li>
            @endforeach
        </ul>
    @endif

    @if ($unmatched->isNotEmpty())
        <x-ui.section-heading class="mt-5">Not on the shelf</x-ui.section-heading>
        <x-ui.card :pad="false" class="mt-2">
            <ul class="divide-y divide-stone-200">
                @foreach ($unmatched as $line)
                    <li class="flex items-center gap-2 px-4 py-3 text-base">
                        <span class="min-w-0 flex-1 truncate text-stone-600">{{ $line->requested_text ?: 'Unknown item' }}</span>
                        <span class="shrink-0 font-semibold tabular-nums text-stone-500">×{{ $line->qty }}</span>
                    </li>
                @endforeach
            </ul>
        </x-ui.card>
    @endif

    <div class="mt-5 flex items-center justify-between border-t border-stone-200 pt-3">
        <span class="text-base font-semibold text-stone-800">Total</span>
        <span class="price text-xl">{{ \App\Livewire\OrderDetailPage::money((float) $order->total) }}</span>
    </div>

    @if (session('packed_notice'))
        <p @class([
            'mt-3 rounded-xl px-4 py-3 text-base font-medium',
            'bg-leaf-100 text-leaf-800' => session('packed_notice')['type'] === 'success',
            'bg-honey-100 text-honey-800' => session('packed_notice')['type'] !== 'success',
        ])>{{ session('packed_notice')['text'] }}</p>
    @endif

    @if ($order->status === \App\Enums\OrderStatus::Confirmed)
        <div class="h-16" aria-hidden="true"></div>
        <div class="fixed inset-x-0 bottom-0 z-10 mx-auto w-full max-w-[480px] bg-gradient-to-t from-white via-white/95 to-transparent px-4 pb-[calc(84px+env(safe-area-inset-bottom))] pt-8">
            <form method="POST" action="{{ route('orders.packed', $order) }}">
                @csrf
                <button type="submit" class="btn btn-primary btn-lg w-full shadow-pop">
                    Packed
                </button>
            </form>
            @error('pack') <p class="error-text">{{ $message }}</p> @enderror
        </div>
    @elseif ($order->status === \App\Enums\OrderStatus::Packed)
        <p class="mt-3 rounded-xl bg-stone-100 px-4 py-4 text-center text-base font-semibold text-stone-600">Packed and ready for pickup.</p>
    @else
        <p class="mt-3 rounded-xl bg-stone-100 px-4 py-4 text-center text-base font-medium text-stone-600">Only confirmed orders can be packed.</p>
    @endif
</div>
