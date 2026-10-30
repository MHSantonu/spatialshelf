<div>
    <x-ui.back-link :href="route('scans.show', $scan)" :label="'Back to scan '.$scan->id" wire:navigate />

    @if ($current === null)
        <x-ui.card class="mt-2">
            <x-ui.empty-state icon="check" title="All done." body="Every product in this scan has been reviewed.">
                <x-ui.button :href="route('stock')" wire:navigate>Go to Stock</x-ui.button>
            </x-ui.empty-state>
        </x-ui.card>
    @else
        <div class="mt-1 h-1.5 overflow-hidden rounded-full bg-stone-200" role="progressbar" aria-label="Review progress" aria-valuenow="{{ $position }}" aria-valuemin="0" aria-valuemax="{{ $total }}">
            <div class="h-full rounded-full bg-leaf-600" style="width: {{ $total > 0 ? (int) round($position / $total * 100) : 0 }}%"></div>
        </div>
        <div class="mt-2 flex items-center justify-between gap-2">
            <h1 class="text-xl font-bold text-stone-900">Review products</h1>
            <p class="text-base tabular-nums text-stone-500">{{ $position }} / {{ $total }}</p>
        </div>
        @if ($unclearRemaining > 0)
            <x-ui.button variant="ghost" wire:click="rejectUnclear" wire:confirm="Reject {{ $unclearRemaining }} unclear cards?" class="mt-1 px-0">
                Reject all unclear ({{ $unclearRemaining }})
            </x-ui.button>
        @endif
        @if ($knownRemaining > 0)
            <div class="mt-2 flex min-h-[44px] flex-wrap items-center gap-x-4 gap-y-2">
                <x-ui.button wire:click="confirmAllKnown" wire:confirm="Confirm {{ $knownRemaining }} known items?" wire:loading.attr="disabled">
                    Confirm all known ({{ $knownRemaining }})
                </x-ui.button>
                <label class="inline-flex min-h-[44px] cursor-pointer items-center gap-2 text-base font-medium text-stone-800">
                    <input type="checkbox" wire:model.live="updateCounts" class="size-5 accent-leaf-700">
                    Update counts
                </label>
            </div>
        @endif
        @if (! $pricingAll)
            <x-ui.button variant="ghost" wire:click="startPriceAll" class="mt-1 px-0">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="size-4" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 18.75a6 6 0 0 0 6-6v-1.5m-6 7.5a6 6 0 0 1-6-6v-1.5m6 7.5v3.75m-3.75 0h7.5M12 15.75a3 3 0 0 1-3-3V4.5a3 3 0 1 1 6 0v8.25a3 3 0 0 1-3 3Z" />
                </svg>
                Price all by voice
            </x-ui.button>
        @else
            <x-ui.card class="mt-2" x-data="{
                recording: false,
                busy: false,
                error: '',
                recorder: null,
                chunks: [],
                timer: null,
                init() {
                    this._nav = () => this.discard();
                    document.addEventListener('livewire:navigating', this._nav);
                },
                destroy() {
                    document.removeEventListener('livewire:navigating', this._nav);
                },
                async start() {
                    this.error = '';
                    if (this.busy || this.recording) return;
                    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia || typeof MediaRecorder === 'undefined') {
                        this.error = 'Voice is not supported in this browser.';
                        return;
                    }
                    try {
                        const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
                        const options = MediaRecorder.isTypeSupported('audio/webm') ? { mimeType: 'audio/webm' } : undefined;
                        const recorder = options ? new MediaRecorder(stream, options) : new MediaRecorder(stream);
                        this.chunks = [];
                        recorder.ondataavailable = (event) => { if (event.data && event.data.size > 0) this.chunks.push(event.data); };
                        recorder.onstop = () => {
                            stream.getTracks().forEach((track) => track.stop());
                            this.upload();
                        };
                        this.recorder = recorder;
                        recorder.start();
                        this.recording = true;
                        this.timer = setTimeout(() => this.finish(), 30000);
                    } catch (e) {
                        this.error = 'Microphone blocked — allow access and try again.';
                    }
                },
                finish() {
                    if (!this.recording || !this.recorder) return;
                    this.recording = false;
                    clearTimeout(this.timer);
                    this.timer = null;
                    this.busy = true;
                    this.recorder.stop();
                },
                discard() {
                    clearTimeout(this.timer);
                    this.timer = null;
                    this.recording = false;
                    this.busy = false;
                    this.chunks = [];
                    if (this.recorder) {
                        const recorder = this.recorder;
                        this.recorder = null;
                        recorder.onstop = () => {};
                        try { recorder.stream.getTracks().forEach((track) => track.stop()); } catch (e) {}
                        if (recorder.state !== 'inactive') { try { recorder.stop(); } catch (e) {} }
                    }
                },
                async upload() {
                    try {
                        const type = (this.recorder && this.recorder.mimeType) || 'audio/webm';
                        const form = new FormData();
                        form.append('audio', new Blob(this.chunks, { type }), 'prices.webm');
                        const token = document.querySelector('meta[name=csrf-token]');
                        const response = await fetch('{{ route('scans.voice-all', $scan) }}', {
                            method: 'POST',
                            headers: {
                                'Accept': 'application/json',
                                'X-Requested-With': 'XMLHttpRequest',
                                'X-CSRF-TOKEN': token ? token.content : '',
                            },
                            body: form,
                        });
                        const data = await response.json().catch(() => ({}));
                        if (!response.ok) throw new Error(data.message || 'Upload failed.');
                        if (!data.items || !data.items.length) throw new Error('No card names heard — try again.');
                        $wire.applyVoiceAll(data.items, data.transcript ?? null);
                    } catch (e) {
                        this.error = (e && e.message) || 'Could not read that — try again.';
                    } finally {
                        this.busy = false;
                        this.recorder = null;
                        this.chunks = [];
                    }
                },
            }">
                <p class="text-base font-semibold text-stone-900">Say each product with its price, then release</p>
                <p class="mt-0.5 text-base text-stone-500">For example: "Horlicks six fifty, Tang four fifty"</p>
                <div class="mt-2 flex gap-2">
                    <button type="button" aria-label="Hold to record all prices"
                        @pointerdown.prevent="start()" @pointerup.window="finish()" @pointercancel.window="finish()" @contextmenu.prevent @keydown.space.prevent="start()" @keyup.space="finish()"
                        :disabled="busy"
                        class="btn btn-primary min-h-[56px] flex-1">
                        <span x-show="!recording && !busy">Hold to record</span>
                        <span x-show="recording" class="inline-flex items-center gap-2"><span class="size-3 rounded-sm bg-white motion-safe:animate-pulse" aria-hidden="true"></span>Listening… release to send</span>
                        <span x-show="busy">Reading prices…</span>
                    </button>
                    <x-ui.button variant="secondary" wire:click="cancelPriceAll" class="min-h-[56px]">Cancel</x-ui.button>
                </div>
                <p x-show="error" x-text="error" class="error-text"></p>
            </x-ui.card>
        @endif

        <div
            wire:key="review-card-{{ $current->id }}"
            x-data="{ startX: 0, dx: 0, dragging: false }"
            @pointerdown="dragging = true; startX = $event.clientX"
            @pointermove.window="if (dragging) dx = Math.max(-160, Math.min(160, $event.clientX - startX))"
            @pointerup.window="dragging = false; if (dx > 100) $wire.confirm(); else if (dx < -100) $wire.reject(); dx = 0"
            @pointercancel.window="dragging = false; dx = 0"
            :style="`transform: translateX(${dx}px) rotate(${dx / 20}deg)`"
            :class="dragging ? '' : 'swipe-settle'"
            class="swipe-card card-enter relative mt-3 touch-pan-y select-none rounded-2xl border border-stone-200 bg-white p-3 shadow-card"
        >
            <div class="pointer-events-none absolute inset-0 z-20 overflow-hidden rounded-2xl" aria-hidden="true">
                <div :style="`opacity: ${Math.max(0, Math.min(1, -dx / 100))}`" class="absolute inset-0 flex items-center justify-center bg-red-600/20">
                    <span class="flex size-16 items-center justify-center rounded-full bg-red-600 text-white shadow-pop">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="3" stroke="currentColor" class="size-8">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
                        </svg>
                    </span>
                </div>
                <div :style="`opacity: ${Math.max(0, Math.min(1, dx / 100))}`" class="absolute inset-0 flex items-center justify-center bg-leaf-600/20">
                    <span class="flex size-16 items-center justify-center rounded-full bg-leaf-600 text-white shadow-pop">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="3" stroke="currentColor" class="size-8">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" />
                        </svg>
                    </span>
                </div>
            </div>

            @php($confidence = (float) (($current->extracted ?? [])['confidence'] ?? 0))
            <div class="skeleton aspect-square w-full rounded-xl bg-stone-100">
                <img src="{{ route('scans.crop', [$scan, basename($current->crop_path)]) }}" alt="{{ $shown['name'] }}" draggable="false"
                    onload="this.parentElement.classList.remove('skeleton')" onerror="this.parentElement.classList.remove('skeleton')"
                    class="relative z-10 aspect-square w-full rounded-xl object-cover">
            </div>
            <div class="mt-2 h-2 overflow-hidden rounded-full bg-stone-200" role="img" aria-label="Confidence {{ (int) round($confidence * 100) }} percent">
                <div class="h-full rounded-full {{ $confidence >= 0.8 ? 'bg-leaf-500' : ($confidence >= 0.5 ? 'bg-honey-500' : 'bg-red-500') }}" style="width: {{ (int) round($confidence * 100) }}%"></div>
            </div>

            <div class="mt-3 flex items-start justify-between gap-2">
                <button type="button" wire:click="startEdit" class="min-h-[44px] text-left text-lg font-bold text-stone-900 hover:underline">
                    {{ $shown['name'] }}
                </button>
                <span class="flex shrink-0 flex-wrap items-center justify-end gap-1.5 pt-2">
                    @if ($current->matched_product_id !== null)
                        <x-ui.badge variant="known">Known</x-ui.badge>
                    @endif
                    @if (\App\Livewire\ScanReviewPage::isUnclear($current))
                        <x-ui.badge variant="unclear">Brand unclear</x-ui.badge>
                    @endif
                    <x-ui.badge
                        title="Confidence {{ number_format($confidence, 2) }}"
                        :variant="$confidence >= 0.8 ? 'high' : ($confidence >= 0.5 ? 'med' : 'low')"
                    >{{ (int) round($confidence * 100) }}%</x-ui.badge>
                </span>
            </div>

            <p class="mt-1 text-base text-stone-600">
                @php($sizeValue = $shown['size_value'])
                <span @class(['rounded px-1', 'bg-leaf-100 font-semibold text-leaf-900' => $voiceFilled])>{{ (is_numeric($sizeValue) && $sizeValue > 0 ? rtrim(rtrim(sprintf('%.2f', (float) $sizeValue), '0'), '.').' '.$shown['size_unit'] : 'size unknown') }}</span>
                · {{ ucfirst(str_replace('_', ' ', $shown['category'])) }}
                · <span class="tnum">~{{ $shown['qty'] }} in stock</span>
            </p>

            @if ($nearest !== null)
                <div class="mt-2 flex items-center justify-between gap-2 rounded-xl bg-honey-50 px-3 py-2 text-base ring-1 ring-honey-200">
                    <p class="text-honey-900">Might be a duplicate of <span class="font-semibold">{{ ($nearest->extracted ?? [])['name'] ?? 'another card' }}</span></p>
                    <button type="button" wire:click="mergeWith({{ $nearest->id }})" class="inline-flex min-h-[44px] shrink-0 items-center rounded-xl bg-honey-700 px-4 text-base font-semibold text-white hover:bg-honey-800">Merge</button>
                </div>
            @endif

            <div class="mt-3" x-data="{
                recording: false,
                busy: false,
                error: '',
                recorder: null,
                chunks: [],
                timer: null,
                init() {
                    this._nav = () => this.discard();
                    document.addEventListener('livewire:navigating', this._nav);
                },
                destroy() {
                    document.removeEventListener('livewire:navigating', this._nav);
                },
                async start() {
                    this.error = '';
                    if (this.busy || this.recording) return;
                    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia || typeof MediaRecorder === 'undefined') {
                        this.error = 'Voice is not supported in this browser.';
                        return;
                    }
                    try {
                        const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
                        const options = MediaRecorder.isTypeSupported('audio/webm') ? { mimeType: 'audio/webm' } : undefined;
                        const recorder = options ? new MediaRecorder(stream, options) : new MediaRecorder(stream);
                        this.chunks = [];
                        recorder.ondataavailable = (event) => { if (event.data && event.data.size > 0) this.chunks.push(event.data); };
                        recorder.onstop = () => {
                            stream.getTracks().forEach((track) => track.stop());
                            this.upload();
                        };
                        this.recorder = recorder;
                        recorder.start();
                        this.recording = true;
                        this.timer = setTimeout(() => this.finish(), 8000);
                    } catch (e) {
                        this.error = 'Microphone blocked — allow access and try again.';
                    }
                },
                finish() {
                    if (!this.recording || !this.recorder) return;
                    this.recording = false;
                    clearTimeout(this.timer);
                    this.timer = null;
                    this.busy = true;
                    this.recorder.stop();
                },
                discard() {
                    clearTimeout(this.timer);
                    this.timer = null;
                    this.recording = false;
                    this.busy = false;
                    this.chunks = [];
                    if (this.recorder) {
                        const recorder = this.recorder;
                        this.recorder = null;
                        recorder.onstop = () => {};
                        try { recorder.stream.getTracks().forEach((track) => track.stop()); } catch (e) {}
                        if (recorder.state !== 'inactive') { try { recorder.stop(); } catch (e) {} }
                    }
                },
                async upload() {
                    try {
                        const type = (this.recorder && this.recorder.mimeType) || 'audio/webm';
                        const form = new FormData();
                        form.append('audio', new Blob(this.chunks, { type }), 'price.webm');
                        const token = document.querySelector('meta[name=csrf-token]');
                        const response = await fetch('{{ route('scan-items.voice', $current) }}', {
                            method: 'POST',
                            headers: {
                                'Accept': 'application/json',
                                'X-Requested-With': 'XMLHttpRequest',
                                'X-CSRF-TOKEN': token ? token.content : '',
                            },
                            body: form,
                        });
                        const data = await response.json().catch(() => ({}));
                        if (!response.ok) throw new Error(data.message || 'Upload failed.');
                        $wire.applyVoice({{ $current->id }}, data.price ?? null, data.size_value ?? null, data.size_unit ?? null, data.transcript ?? null);
                    } catch (e) {
                        this.error = (e && e.message) || 'Could not read that — try again.';
                    } finally {
                        this.busy = false;
                        this.recorder = null;
                        this.chunks = [];
                    }
                },
            }">
                <label for="review-price" class="label">Price (৳)</label>
                <div class="mt-1 flex gap-2">
                    <input id="review-price" type="number" min="0" step="0.01" inputmode="decimal" wire:model.live="price" @class(['input price text-lg', 'border-leaf-500 bg-leaf-50' => $voiceFilled])>
                    <button type="button" title="Hold to say the price" aria-label="Hold to say the price"
                        @pointerdown.prevent="start()" @pointerup.window="finish()" @pointercancel.window="finish()" @contextmenu.prevent @keydown.space.prevent="start()" @keyup.space="finish()"
                        :disabled="busy"
                        class="flex min-h-[48px] w-16 shrink-0 items-center justify-center rounded-xl border border-leaf-600 bg-leaf-50 text-leaf-800 shadow-sm hover:bg-leaf-100 disabled:opacity-50">
                        <svg x-show="!recording && !busy" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="size-6" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 18.75a6 6 0 0 0 6-6v-1.5m-6 7.5a6 6 0 0 1-6-6v-1.5m6 7.5v3.75m-3.75 0h7.5M12 15.75a3 3 0 0 1-3-3V4.5a3 3 0 1 1 6 0v8.25a3 3 0 0 1-3 3Z" />
                        </svg>
                        <span x-show="recording" class="size-3 rounded-sm bg-red-600 motion-safe:animate-pulse" aria-hidden="true"></span>
                        <span x-show="busy" class="size-5 rounded-full border-2 border-stone-300 border-t-leaf-700 motion-safe:animate-spin" aria-hidden="true"></span>
                    </button>
                </div>
                @error('price')
                    <p class="error-text">{{ $message }}</p>
                @enderror
                <p x-show="error" x-text="error" class="error-text"></p>
                @if ($voiceTranscript !== null)
                    <p class="mt-1 text-base text-stone-600">Heard: "{{ $voiceTranscript }}"</p>
                @endif
            </div>

            <div class="mt-3 grid grid-cols-2 gap-2">
                <button type="button" wire:click="reject" wire:loading.attr="disabled" aria-label="Reject"
                    class="inline-flex min-h-[56px] items-center justify-center rounded-xl border border-stone-300 bg-white text-red-600 hover:bg-red-50 disabled:opacity-50">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor" class="size-7" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
                    </svg>
                </button>
                <button type="button" wire:click="confirm" wire:loading.attr="disabled" @disabled(! is_numeric($price) || (float) $price <= 0) aria-label="Confirm"
                    class="btn btn-primary min-h-[56px] px-2">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor" class="size-7" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" />
                    </svg>
                </button>
            </div>
            <button type="button" wire:click="startPick" class="btn btn-secondary mt-2 w-full">Same as…</button>
        </div>
        <p class="mt-2 text-center text-base text-stone-500">Drag the card, or use the buttons.</p>
    @endif

    @if ($editing)
        <div class="fixed inset-0 z-50 flex items-end justify-center bg-black/50 p-4" role="dialog" aria-modal="true" aria-label="Edit product">
            <div class="w-full max-w-[440px] rounded-2xl bg-white p-4 shadow-pop">
                <h2 class="text-lg font-bold text-stone-900">Edit product</h2>
                <div class="mt-3 space-y-3">
                    <div>
                        <label for="edit-name" class="label">Name</label>
                        <input id="edit-name" type="text" wire:model="form.name" class="input mt-1">
                        @error('form.name') <p class="error-text">{{ $message }}</p> @enderror
                    </div>
                    <div class="grid grid-cols-2 gap-2">
                        <div>
                            <label for="edit-size-value" class="label">Size</label>
                            <input id="edit-size-value" type="number" min="0" step="any" inputmode="decimal" wire:model="form.size_value" class="input mt-1">
                            @error('form.size_value') <p class="error-text">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label for="edit-size-unit" class="label">Unit</label>
                            <select id="edit-size-unit" wire:model="form.size_unit" class="input mt-1 bg-white">
                                @foreach (\App\Livewire\ScanReviewPage::SIZE_UNITS as $unit)
                                    <option value="{{ $unit }}">{{ $unit }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                    <div class="grid grid-cols-2 gap-2">
                        <div>
                            <label for="edit-category" class="label">Category</label>
                            <select id="edit-category" wire:model="form.category" class="input mt-1 bg-white">
                                @foreach (\App\Livewire\ScanReviewPage::CATEGORIES as $category)
                                    <option value="{{ $category }}">{{ ucfirst(str_replace('_', ' ', $category)) }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label for="edit-qty" class="label">Est. stock</label>
                            <input id="edit-qty" type="number" min="0" step="1" inputmode="numeric" wire:model="form.qty" class="input mt-1">
                            @error('form.qty') <p class="error-text">{{ $message }}</p> @enderror
                        </div>
                    </div>
                </div>
                <div class="mt-4 grid grid-cols-2 gap-2">
                    <x-ui.button variant="secondary" wire:click="cancelEdit">Cancel</x-ui.button>
                    <x-ui.button wire:click="saveEdit">Save</x-ui.button>
                </div>
            </div>
        </div>
    @endif

    @if ($picking)
        <div class="fixed inset-0 z-50 flex items-end justify-center bg-black/50 p-4" role="dialog" aria-modal="true" aria-label="Pick the same product">
            <div class="max-h-[80dvh] w-full max-w-[440px] overflow-y-auto rounded-2xl bg-white p-4 shadow-pop">
                <div class="flex items-center justify-between gap-2">
                    <h2 class="text-lg font-bold text-stone-900">Same as…</h2>
                    <button type="button" wire:click="cancelPick" aria-label="Close" class="inline-flex min-h-[44px] min-w-[44px] items-center justify-center rounded-lg text-stone-600 hover:text-stone-900">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.2" stroke="currentColor" class="size-5" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>
                <ul class="mt-2 space-y-2">
                    @forelse ($others as $other)
                        <li>
                            <button type="button" wire:click="mergeWith({{ $other->id }})" class="flex min-h-[64px] w-full items-center gap-3 rounded-xl border border-stone-200 bg-white p-2 text-left shadow-card">
                                <img src="{{ route('scans.crop', [$scan, basename($other->crop_path)]) }}" alt="" class="size-12 shrink-0 rounded-lg bg-stone-100 object-cover">
                                <span class="min-w-0">
                                    <span class="block truncate text-base font-semibold text-stone-900">{{ ($other->extracted ?? [])['name'] ?? 'Unnamed product' }}</span>
                                    <span class="block text-base text-stone-500">Row {{ $other->shelf_row }}{{ $other->shelf_row === $current?->shelf_row ? ' · same row' : '' }}</span>
                                </span>
                            </button>
                        </li>
                    @empty
                        <li class="py-4 text-center text-base text-stone-500">No other cards in this scan.</li>
                    @endforelse
                </ul>
            </div>
        </div>
    @endif
</div>
