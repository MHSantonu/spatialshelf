<div wire:poll.5s>
    <div class="flex items-center gap-2">
        <h1 class="text-xl font-bold text-stone-900">Orders</h1>
        @if ($newCount > 0)
            <x-ui.badge variant="new">{{ $newCount }} new</x-ui.badge>
        @endif
    </div>

    @if ($orders->isEmpty())
        <x-ui.card class="mt-3">
            <x-ui.empty-state icon="bag" title="No orders yet" body="WhatsApp orders will appear here." />
        </x-ui.card>
    @else
        <div wire:loading.delay class="mt-2 flex items-center gap-2 text-base text-stone-500">
            <span class="size-4 shrink-0 rounded-full border-2 border-stone-300 border-t-leaf-700 motion-safe:animate-spin" aria-hidden="true"></span>
            Checking for new orders…
        </div>
        <ul class="mt-3 space-y-2">
            @foreach ($orders as $order)
                <li>
                    <a href="{{ route('orders.show', $order) }}" wire:navigate
                       class="block rounded-xl border border-stone-200 bg-white px-4 py-3 shadow-card">
                        <span class="flex items-center gap-2">
                            <span class="text-base font-bold tabular-nums text-stone-900">#{{ $order->id }}</span>
                            <span class="min-w-0 flex-1 truncate text-base text-stone-600">{{ \App\Livewire\OrdersPage::customerName($order) }}</span>
                            @if ($order->channel() === 'whatsapp')
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="size-5 shrink-0 text-leaf-700" role="img" aria-label="WhatsApp order">
                                    <title>WhatsApp</title>
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M8.625 12a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm0 0H8.25m4.125 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm0 0H12m4.125 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm0 0h-.375M21 12c0 4.556-4.03 8.25-9 8.25a9.764 9.764 0 0 1-2.555-.337A5.972 5.972 0 0 1 5.41 20.97a5.969 5.969 0 0 1-.474-.065 4.48 4.48 0 0 0 .978-2.025c.09-.457-.133-.901-.467-1.226C3.93 16.178 3 14.189 3 12c0-4.556 4.03-8.25 9-8.25s9 3.694 9 8.25Z" />
                                </svg>
                            @else
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="size-5 shrink-0 text-stone-400" role="img" aria-label="Try demo order">
                                    <title>Try demo</title>
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 17.25v1.007a3 3 0 0 1-.879 2.122L7.5 21h9l-.621-.621A3 3 0 0 1 15 18.257V17.25m6-12V15a2.25 2.25 0 0 1-2.25 2.25H5.25A2.25 2.25 0 0 1 3 15V5.25m18 0A2.25 2.25 0 0 0 18.75 3H5.25A2.25 2.25 0 0 0 3 5.25m18 0V12a2.25 2.25 0 0 1-2.25 2.25H5.25A2.25 2.25 0 0 1 3 12V5.25" />
                                </svg>
                            @endif
                            <x-ui.badge :variant="$order->status === \App\Enums\OrderStatus::Confirmed ? 'ok' : 'neutral'">{{ ucfirst($order->status->value) }}</x-ui.badge>
                        </span>
                        <span class="mt-1 flex items-center gap-2 text-base text-stone-500">
                            <span class="tabular-nums">{{ \App\Livewire\OrdersPage::itemCount($order) }} items</span>
                            <span aria-hidden="true">·</span>
                            <span class="price text-stone-900">{{ \App\Livewire\OrdersPage::money((float) $order->total) }}</span>
                            <span aria-hidden="true">·</span>
                            <time datetime="{{ $order->created_at?->toIso8601String() }}" title="{{ $order->created_at?->format('j M Y, g:i a') }}">{{ $order->created_at?->diffForHumans() }}</time>
                        </span>
                    </a>
                </li>
            @endforeach
        </ul>
    @endif
</div>
