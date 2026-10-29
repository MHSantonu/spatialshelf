<div>
    <a href="{{ route('orders') }}" wire:navigate class="inline-flex min-h-[44px] items-center gap-1 text-sm font-medium text-zinc-600 hover:text-zinc-900 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-600">
        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="size-4" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5L8.25 12l7.5-7.5" />
        </svg>
        Orders
    </a>

    <div class="mt-1 flex items-center gap-2">
        <h1 class="text-xl font-bold tabular-nums">Order #{{ $order->id }}</h1>
        <span @class([
            'inline-flex items-center rounded-full px-2 py-0.5 text-xs font-semibold',
            'bg-emerald-100 text-emerald-800' => $order->status === \App\Enums\OrderStatus::Confirmed,
            'bg-zinc-100 text-zinc-600' => $order->status !== \App\Enums\OrderStatus::Confirmed,
        ])>{{ ucfirst($order->status->value) }}</span>
    </div>
    <p class="mt-1 text-sm text-zinc-500">
        {{ \App\Livewire\OrderDetailPage::customerName($order) }}
        · {{ $order->channel() === 'whatsapp' ? 'WhatsApp' : 'Try demo' }}
        · {{ $order->created_at?->format('j M Y, g:i a') }}
    </p>

    <h2 class="mt-5 text-sm font-semibold text-zinc-500">Original input</h2>
    <div class="mt-1 rounded-xl border border-zinc-200 p-3">
        @if ($order->source_type === 'photo' && $order->raw_input_path)
            <a href="{{ route('orders.input', $order) }}" target="_blank" rel="noopener" class="block focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-600">
                <img src="{{ route('orders.input', $order) }}" alt="Customer's handwritten list photo" class="max-h-64 w-full rounded-lg bg-zinc-100 object-contain">
            </a>
            @if (\App\Livewire\OrderDetailPage::inputText($order))
                <p class="mt-2 text-sm">{{ \App\Livewire\OrderDetailPage::inputText($order) }}</p>
            @endif
        @elseif ($order->source_type === 'voice' && $order->raw_input_path)
            <audio controls preload="metadata" src="{{ route('orders.input', $order) }}" class="w-full">Voice note</audio>
            @if ($order->transcript)
                <p class="mt-2 text-sm">{{ $order->transcript }}</p>
            @endif
        @else
            <p class="text-sm">{{ \App\Livewire\OrderDetailPage::inputText($order) ?? 'Original message not saved.' }}</p>
        @endif
    </div>

    <div class="mt-5 flex items-baseline justify-between gap-2">
        <h2 class="text-sm font-semibold text-zinc-500">Picking route</h2>
        @if ($route->isNotEmpty())
            <p class="text-sm tabular-nums text-zinc-500">{{ $pickedCount }} of {{ $route->count() }} picked</p>
        @endif
    </div>
    @if ($route->isEmpty())
        <p class="mt-1 text-sm text-zinc-600">Nothing to pick: no items matched the shelf.</p>
    @else
        <ul class="mt-1 divide-y divide-zinc-200 rounded-xl border border-zinc-200">
            @foreach ($route as $line)
                <li>
                    <label class="flex min-h-[72px] cursor-pointer items-center gap-3 px-3 py-2">
                        <input type="checkbox" wire:model="picked.{{ $line->id }}" value="1"
                            class="size-6 shrink-0 accent-emerald-600" aria-label="Picked {{ $line->product->name }}">
                        @php($thumb = $line->product->images->sortByDesc('id')->first())
                        @if ($thumb)
                            <img src="{{ route('product-images.show', $thumb) }}" alt="" class="size-14 shrink-0 rounded-lg bg-zinc-100 object-cover">
                        @else
                            <span class="flex size-14 shrink-0 items-center justify-center rounded-lg bg-zinc-100 text-xl font-bold text-zinc-400" aria-hidden="true">{{ mb_substr($line->product->name, 0, 1) }}</span>
                        @endif
                        <span class="min-w-0 flex-1">
                            <span class="block text-xs font-medium tabular-nums text-zinc-500">{{ \App\Livewire\OrderDetailPage::shelfLabel($line->product) }}</span>
                            <span class="block truncate font-semibold">{{ $line->product->name }}</span>
                        </span>
                        <span class="shrink-0 text-right">
                            <span class="block font-bold tabular-nums">×{{ $line->qty }}</span>
                            <span class="block text-sm tabular-nums text-zinc-500">{{ \App\Livewire\OrderDetailPage::money((float) $line->line_total) }}</span>
                        </span>
                    </label>
                </li>
            @endforeach
        </ul>
    @endif

    @if ($unmatched->isNotEmpty())
        <h2 class="mt-5 text-sm font-semibold text-zinc-500">Not on the shelf</h2>
        <ul class="mt-1 divide-y divide-zinc-200 rounded-xl border border-zinc-200">
            @foreach ($unmatched as $line)
                <li class="flex items-center gap-2 px-3 py-2.5 text-sm">
                    <span class="min-w-0 flex-1 truncate text-zinc-600">{{ $line->requested_text ?: 'Unknown item' }}</span>
                    <span class="shrink-0 font-semibold tabular-nums text-zinc-500">×{{ $line->qty }}</span>
                </li>
            @endforeach
        </ul>
    @endif

    <div class="mt-5 flex items-center justify-between border-t border-zinc-200 pt-3">
        <span class="font-semibold">Total</span>
        <span class="text-lg font-bold tabular-nums">{{ \App\Livewire\OrderDetailPage::money((float) $order->total) }}</span>
    </div>

    @if (session('packed_notice'))
        <p @class([
            'mt-3 rounded-xl px-3 py-3 text-sm font-medium',
            'bg-emerald-100 text-emerald-800' => session('packed_notice')['type'] === 'success',
            'bg-amber-100 text-amber-800' => session('packed_notice')['type'] !== 'success',
        ])>{{ session('packed_notice')['text'] }}</p>
    @endif

    @if ($order->status === \App\Enums\OrderStatus::Confirmed)
        <form method="POST" action="{{ route('orders.packed', $order) }}" class="mt-3">
            @csrf
            <button type="submit"
                class="inline-flex min-h-[56px] w-full items-center justify-center rounded-xl bg-emerald-600 text-lg font-bold text-white hover:bg-emerald-700 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-600">
                Packed
            </button>
        </form>
        @error('pack') <p class="mt-2 text-sm font-medium text-red-700">{{ $message }}</p> @enderror
    @elseif ($order->status === \App\Enums\OrderStatus::Packed)
        <p class="mt-3 rounded-xl bg-zinc-100 px-3 py-4 text-center font-semibold text-zinc-600">Packed and ready for pickup.</p>
    @else
        <p class="mt-3 rounded-xl bg-zinc-100 px-3 py-4 text-center text-sm font-medium text-zinc-600">Only confirmed orders can be packed.</p>
    @endif
</div>
