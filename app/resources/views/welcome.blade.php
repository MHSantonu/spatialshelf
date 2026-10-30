@component('layouts.visitor')
    <div class="py-6 text-center">
        <p class="text-2xl font-bold leading-snug text-stone-900">Turn a 10-second shelf video into WhatsApp orders.</p>
        <p class="mt-2 text-base text-stone-600">A live catalog for small grocery shops — no data entry.</p>
    </div>

    <ol class="space-y-2">
        <li class="flex items-center gap-3 rounded-xl border border-stone-200 bg-white p-3 shadow-card">
            <span class="flex size-8 shrink-0 items-center justify-center rounded-full bg-leaf-700 text-sm font-bold text-white" aria-hidden="true">1</span>
            <span class="flex size-12 shrink-0 items-center justify-center rounded-xl bg-leaf-50 text-leaf-700" aria-hidden="true">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="size-6">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6.827 6.175A2.31 2.31 0 0 1 5.186 7.23c-.38.054-.757.112-1.134.175C2.999 7.58 2.25 8.507 2.25 9.574V18a2.25 2.25 0 0 0 2.25 2.25h15A2.25 2.25 0 0 0 21.75 18V9.574c0-1.067-.75-1.994-1.802-2.169a47.865 47.865 0 0 0-1.134-.175 2.31 2.31 0 0 1-1.64-1.055l-.822-1.316a2.192 2.192 0 0 0-1.736-1.039 48.774 48.774 0 0 0-5.232 0 2.192 2.192 0 0 0-1.736 1.039l-.821 1.316Z" />
                    <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 12.75a4.5 4.5 0 1 1-9 0 4.5 4.5 0 0 1 9 0Z" />
                </svg>
            </span>
            <span class="min-w-0">
                <span class="block text-base font-bold text-stone-900">Scan shelf</span>
                <span class="block text-base text-stone-600">Pan your phone across the shelf once.</span>
            </span>
        </li>
        <li class="flex items-center gap-3 rounded-xl border border-stone-200 bg-white p-3 shadow-card">
            <span class="flex size-8 shrink-0 items-center justify-center rounded-full bg-leaf-700 text-sm font-bold text-white" aria-hidden="true">2</span>
            <span class="flex size-12 shrink-0 items-center justify-center rounded-xl bg-leaf-50 text-leaf-700" aria-hidden="true">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="size-6">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M8.625 12a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm0 0H8.25m4.125 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm0 0H12m4.125 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm0 0h-.375M21 12c0 4.556-4.03 8.25-9 8.25a9.764 9.764 0 0 1-2.555-.337A5.972 5.972 0 0 1 5.41 20.97a5.969 5.969 0 0 1-.474-.065 4.48 4.48 0 0 0 .978-2.025c.09-.457-.133-.901-.467-1.226C3.93 16.178 3 14.189 3 12c0-4.556 4.03-8.25 9-8.25s9 3.694 9 8.25Z" />
                </svg>
            </span>
            <span class="min-w-0">
                <span class="block text-base font-bold text-stone-900">Customers order on WhatsApp</span>
                <span class="block text-base text-stone-600">By text, list photo or voice note.</span>
            </span>
        </li>
        <li class="flex items-center gap-3 rounded-xl border border-stone-200 bg-white p-3 shadow-card">
            <span class="flex size-8 shrink-0 items-center justify-center rounded-full bg-leaf-700 text-sm font-bold text-white" aria-hidden="true">3</span>
            <span class="flex size-12 shrink-0 items-center justify-center rounded-xl bg-leaf-50 text-leaf-700" aria-hidden="true">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="size-6">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M20.25 7.5l-.625 10.632a2.25 2.25 0 0 1-2.247 2.118H6.622a2.25 2.25 0 0 1-2.247-2.118L3.75 7.5m6 4.125l2.25 2.25m0-2.25l2.25 2.25M3.375 7.5h17.25c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125Z" />
                </svg>
            </span>
            <span class="min-w-0">
                <span class="block text-base font-bold text-stone-900">Stock updates</span>
                <span class="block text-base text-stone-600">Confirmed orders decrement the shelf.</span>
            </span>
        </li>
    </ol>

    <div class="mt-4 space-y-2">
        <x-ui.button :href="route('try')" size="lg" class="w-full">Try ordering</x-ui.button>
        <x-ui.button :href="route('login')" variant="secondary" size="lg" class="w-full">View demo shop</x-ui.button>
    </div>

    <p class="mt-6 text-center text-sm text-stone-500">Built with Meta's SAM 3.1, Muse Spark, Muse Voice Transcribe and DINOv2 for the Meta Global AI Developer Hackathon.</p>
@endcomponent
