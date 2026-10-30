@component('layouts.mobile')
    <x-ui.page-heading title="Styleguide" sub="SpatialShelf design system. Hidden page — not linked anywhere." />

    <x-ui.section-heading class="mt-6">Colours</x-ui.section-heading>
    <x-ui.card class="mt-2">
        <p class="text-base font-semibold text-stone-800">Primary · leaf (warm deep green)</p>
        <div class="mt-2 grid grid-cols-11 gap-1" aria-hidden="true">
            <span class="h-10 rounded-md bg-leaf-50 ring-1 ring-stone-200"></span>
            <span class="h-10 rounded-md bg-leaf-100"></span>
            <span class="h-10 rounded-md bg-leaf-200"></span>
            <span class="h-10 rounded-md bg-leaf-300"></span>
            <span class="h-10 rounded-md bg-leaf-400"></span>
            <span class="h-10 rounded-md bg-leaf-500"></span>
            <span class="h-10 rounded-md bg-leaf-600"></span>
            <span class="h-10 rounded-md bg-leaf-700"></span>
            <span class="h-10 rounded-md bg-leaf-800"></span>
            <span class="h-10 rounded-md bg-leaf-900"></span>
            <span class="h-10 rounded-md bg-leaf-950"></span>
        </div>
        <p class="tnum mt-1 text-sm text-stone-500">50 #eef7f0 · 700 #1d5333 (buttons) · white on 700 ≈ 9:1</p>

        <p class="mt-4 text-base font-semibold text-stone-800">Accent · honey (amber)</p>
        <div class="mt-2 grid grid-cols-7 gap-1" aria-hidden="true">
            <span class="h-10 rounded-md bg-honey-50 ring-1 ring-stone-200"></span>
            <span class="h-10 rounded-md bg-honey-100"></span>
            <span class="h-10 rounded-md bg-honey-200"></span>
            <span class="h-10 rounded-md bg-honey-500"></span>
            <span class="h-10 rounded-md bg-honey-600"></span>
            <span class="h-10 rounded-md bg-honey-700"></span>
            <span class="h-10 rounded-md bg-honey-800"></span>
        </div>
        <p class="tnum mt-1 text-sm text-stone-500">700 #b45309 · 800 #92400e (small text)</p>

        <p class="mt-4 text-base font-semibold text-stone-800">Status</p>
        <div class="mt-2 flex flex-wrap gap-1.5">
            <x-ui.badge variant="ok">In stock</x-ui.badge>
            <x-ui.badge variant="warn">Low · 3 left</x-ui.badge>
            <x-ui.badge variant="bad">Out of stock</x-ui.badge>
            <x-ui.badge variant="neutral">Pending</x-ui.badge>
        </div>
    </x-ui.card>

    <x-ui.section-heading class="mt-6">Type</x-ui.section-heading>
    <x-ui.card class="mt-2 space-y-2">
        <p class="text-xl font-bold text-stone-900">Heading · Rupchanda Soybean Oil</p>
        <p class="text-base text-stone-600">Body · 2 ta tel, 1 kg dal — mixed Bangla and English.</p>
        <p class="text-base text-stone-900">সরিষার তেল · মসুর ডাল · চিনি ১ কেজি</p>
        <p class="price text-lg text-stone-900">৳380 · ৳1,250 · ~12 in stock</p>
        <p class="tnum text-base text-stone-500">Tabular: 111 → 888 align in totals</p>
    </x-ui.card>

    <x-ui.section-heading class="mt-6">Buttons · 48px+</x-ui.section-heading>
    <x-ui.card class="mt-2 space-y-2">
        <x-ui.button class="w-full">Primary</x-ui.button>
        <x-ui.button variant="secondary" class="w-full">Secondary</x-ui.button>
        <x-ui.button size="lg" class="w-full">Large hero action</x-ui.button>
        <x-ui.button variant="ghost">Quiet text action</x-ui.button>
        <x-ui.button disabled class="w-full">Disabled</x-ui.button>
    </x-ui.card>

    <x-ui.section-heading class="mt-6">Badges</x-ui.section-heading>
    <x-ui.card class="mt-2">
        <div class="flex flex-wrap gap-1.5">
            <x-ui.badge variant="known">Known</x-ui.badge>
            <x-ui.badge variant="unclear">Brand unclear</x-ui.badge>
            <x-ui.badge variant="high">96%</x-ui.badge>
            <x-ui.badge variant="med">64%</x-ui.badge>
            <x-ui.badge variant="low">21%</x-ui.badge>
            <x-ui.badge variant="new">2 new</x-ui.badge>
        </div>
    </x-ui.card>

    <x-ui.section-heading class="mt-6">Progress steps</x-ui.section-heading>
    <x-ui.card class="mt-2">
        <x-ui.progress-steps :steps="['Preparing video', 'Finding products', 'Reading labels']" :current="1" :seconds="[2.4, null, null]" />
    </x-ui.card>

    <x-ui.section-heading class="mt-6">Phrase chips</x-ui.section-heading>
    <x-ui.card class="mt-2">
        <x-ui.phrase-chips :picked="['oil bottle', 'chips packet', 'soap bar']" source="picked" />
    </x-ui.card>

    <x-ui.section-heading class="mt-6">Form controls</x-ui.section-heading>
    <x-ui.card class="mt-2 space-y-3">
        <div>
            <label class="label" for="sg-price">Price (৳)</label>
            <input id="sg-price" type="number" value="380" class="input price mt-1">
        </div>
        <div>
            <label class="label" for="sg-note">Note</label>
            <textarea id="sg-note" rows="2" placeholder="2 Tang Orange…" class="textarea mt-1"></textarea>
        </div>
        <p class="error-text">Price must be at least ৳0.01.</p>
    </x-ui.card>

    <x-ui.section-heading class="mt-6">Empty state</x-ui.section-heading>
    <x-ui.card class="mt-2" :pad="false">
        <x-ui.empty-state icon="bag" title="No orders yet" body="WhatsApp orders will appear here." />
    </x-ui.card>

    <x-ui.section-heading class="mt-6">Loading</x-ui.section-heading>
    <div class="mt-2 space-y-2">
        <x-ui.skeleton :count="2" />
    </div>

    <x-ui.section-heading class="mt-6">Motion</x-ui.section-heading>
    <x-ui.card class="mt-2">
        <ul class="list-disc space-y-1 pl-5 text-base text-stone-600">
            <li>Review cards settle after a swipe, new cards rise 10px.</li>
            <li>Finished steps tick in with a small pop.</li>
            <li>A saved stock row flashes green once.</li>
            <li>Skeletons shimmer while loading.</li>
            <li>All of it stops under prefers-reduced-motion.</li>
        </ul>
    </x-ui.card>

    <div class="h-4"></div>
@endcomponent
