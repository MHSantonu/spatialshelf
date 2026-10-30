<nav class="fixed bottom-0 left-1/2 z-10 w-full max-w-[480px] -translate-x-1/2 border-t border-stone-200 bg-white/95 backdrop-blur" aria-label="Primary">
    <div class="grid grid-cols-3">
        <a href="{{ route('scan') }}" wire:navigate
           @class(['flex min-h-[64px] flex-col items-center justify-center gap-1 text-sm font-semibold', 'text-leaf-700' => request()->routeIs('scan'), 'text-stone-500 hover:text-stone-800' => ! request()->routeIs('scan')])
           @if(request()->routeIs('scan')) aria-current="page" @endif>
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="size-6" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M6.827 6.175A2.31 2.31 0 0 1 5.186 7.23c-.38.054-.757.112-1.134.175C2.999 7.58 2.25 8.507 2.25 9.574V18a2.25 2.25 0 0 0 2.25 2.25h15A2.25 2.25 0 0 0 21.75 18V9.574c0-1.067-.75-1.994-1.802-2.169a47.865 47.865 0 0 0-1.134-.175 2.31 2.31 0 0 1-1.64-1.055l-.822-1.316a2.192 2.192 0 0 0-1.736-1.039 48.774 48.774 0 0 0-5.232 0 2.192 2.192 0 0 0-1.736 1.039l-.821 1.316Z" />
                <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 12.75a4.5 4.5 0 1 1-9 0 4.5 4.5 0 0 1 9 0Z" />
            </svg>
            Scan
        </a>
        <a href="{{ route('stock') }}" wire:navigate
           @class(['flex min-h-[64px] flex-col items-center justify-center gap-1 text-sm font-semibold', 'text-leaf-700' => request()->routeIs('stock'), 'text-stone-500 hover:text-stone-800' => ! request()->routeIs('stock')])
           @if(request()->routeIs('stock')) aria-current="page" @endif>
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="size-6" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M20.25 7.5l-.625 10.632a2.25 2.25 0 0 1-2.247 2.118H6.622a2.25 2.25 0 0 1-2.247-2.118L3.75 7.5m6 4.125l2.25 2.25m0-2.25l2.25 2.25M3.375 7.5h17.25c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125Z" />
            </svg>
            Stock
        </a>
        <a href="{{ route('orders') }}" wire:navigate
           @class(['flex min-h-[64px] flex-col items-center justify-center gap-1 text-sm font-semibold', 'text-leaf-700' => request()->routeIs('orders*'), 'text-stone-500 hover:text-stone-800' => ! request()->routeIs('orders*')])
           @if(request()->routeIs('orders*')) aria-current="page" @endif>
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="size-6" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 10.5V6a3.75 3.75 0 1 0-7.5 0v4.5m11.356-1.993 1.263 12c.07.665-.45 1.243-1.119 1.243H4.25a1.125 1.125 0 0 1-1.12-1.243l1.264-12A1.125 1.125 0 0 1 5.513 7.5h12.974c.576 0 1.059.435 1.119 1.007Z" />
            </svg>
            Orders
        </a>
    </div>
    <div class="h-[env(safe-area-inset-bottom)]"></div>
</nav>
