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
        @if ($knownRemaining > 0)
            <div class="mt-2 flex min-h-[44px] flex-wrap items-center gap-x-4 gap-y-2">
                <button type="button" wire:click="confirmAllKnown" wire:confirm="Confirm {{ $knownRemaining }} known items?" wire:loading.attr="disabled" class="inline-flex min-h-[44px] items-center rounded-xl bg-emerald-600 px-4 text-sm font-semibold text-white hover:bg-emerald-700 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-600 disabled:opacity-50">
                    Confirm all known ({{ $knownRemaining }})
                </button>
                <label class="inline-flex min-h-[44px] cursor-pointer items-center gap-2 text-sm font-medium">
                    <input type="checkbox" wire:model.live="updateCounts" class="size-5 accent-emerald-600">
                    Update counts
                </label>
            </div>
        @endif
        @if (! $pricingAll)
            <button type="button" wire:click="startPriceAll" class="mt-2 inline-flex min-h-[44px] items-center gap-2 text-sm font-medium text-zinc-600 underline underline-offset-2 hover:text-zinc-900 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-600">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="size-4" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 18.75a6 6 0 0 0 6-6v-1.5m-6 7.5a6 6 0 0 1-6-6v-1.5m6 7.5v3.75m-3.75 0h7.5M12 15.75a3 3 0 0 1-3-3V4.5a3 3 0 1 1 6 0v8.25a3 3 0 0 1-3 3Z" />
                </svg>
                Price all by voice
            </button>
        @else
            <div class="mt-2 rounded-xl border border-zinc-200 p-3" x-data="{
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
                <p class="text-sm font-medium">Say each product with its price, then release</p>
                <p class="mt-0.5 text-sm text-zinc-500">For example: "Horlicks six fifty, Tang four fifty"</p>
                <div class="mt-2 flex gap-2">
                    <button type="button" aria-label="Hold to record all prices"
                        @pointerdown.prevent="start()" @pointerup.window="finish()" @pointercancel.window="finish()" @contextmenu.prevent @keydown.space.prevent="start()" @keyup.space="finish()"
                        :disabled="busy"
                        class="inline-flex min-h-[56px] flex-1 items-center justify-center gap-2 rounded-xl bg-emerald-600 font-semibold text-white hover:bg-emerald-700 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-600 disabled:opacity-50">
                        <span x-show="!recording && !busy">Hold to record</span>
                        <span x-show="recording" class="inline-flex items-center gap-2"><span class="size-3 rounded-sm bg-white motion-safe:animate-pulse" aria-hidden="true"></span>Listening… release to send</span>
                        <span x-show="busy">Reading prices…</span>
                    </button>
                    <button type="button" wire:click="cancelPriceAll" class="inline-flex min-h-[56px] items-center rounded-xl border border-zinc-300 px-4 font-semibold hover:bg-zinc-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-600">Cancel</button>
                </div>
                <p x-show="error" x-text="error" class="mt-2 text-sm font-medium text-red-700"></p>
            </div>
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
                <span @class(['rounded px-1', 'bg-emerald-100 font-medium text-emerald-900' => $voiceFilled])>{{ (is_numeric($sizeValue) && $sizeValue > 0 ? rtrim(rtrim(sprintf('%.2f', (float) $sizeValue), '0'), '.').' '.$shown['size_unit'] : 'size unknown') }}</span>
                · {{ ucfirst(str_replace('_', ' ', $shown['category'])) }}
                · ~{{ $shown['qty'] }} in stock
            </p>

            @if ($nearest !== null)
                <div class="mt-2 flex items-center justify-between gap-2 rounded-lg bg-amber-50 px-3 py-2 text-sm">
                    <p class="text-amber-900">Might be a duplicate of <span class="font-semibold">{{ ($nearest->extracted ?? [])['name'] ?? 'another card' }}</span></p>
                    <button type="button" wire:click="mergeWith({{ $nearest->id }})" class="inline-flex min-h-[44px] shrink-0 items-center rounded-lg bg-amber-600 px-3 font-semibold text-white hover:bg-amber-700 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-amber-600">Merge</button>
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
                <label for="review-price" class="text-sm font-medium">Price (৳)</label>
                <div class="mt-1 flex gap-2">
                    <input id="review-price" type="number" min="0" step="0.01" inputmode="decimal" wire:model.live="price" @class(['flex min-h-[48px] w-full min-w-0 items-center rounded-xl border px-3 text-lg focus:outline-none', 'border-emerald-500 bg-emerald-50 focus:border-emerald-600' => $voiceFilled, 'border-zinc-300 focus:border-emerald-600' => ! $voiceFilled])>
                    <button type="button" title="Hold to say the price" aria-label="Hold to say the price"
                        @pointerdown.prevent="start()" @pointerup.window="finish()" @pointercancel.window="finish()" @contextmenu.prevent @keydown.space.prevent="start()" @keyup.space="finish()"
                        :disabled="busy"
                        class="flex min-h-[48px] w-14 shrink-0 items-center justify-center rounded-xl border border-zinc-300 hover:bg-zinc-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-600 disabled:opacity-50">
                        <svg x-show="!recording && !busy" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="size-6" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 18.75a6 6 0 0 0 6-6v-1.5m-6 7.5a6 6 0 0 1-6-6v-1.5m6 7.5v3.75m-3.75 0h7.5M12 15.75a3 3 0 0 1-3-3V4.5a3 3 0 1 1 6 0v8.25a3 3 0 0 1-3 3Z" />
                        </svg>
                        <span x-show="recording" class="size-3 rounded-sm bg-red-600 motion-safe:animate-pulse" aria-hidden="true"></span>
                        <span x-show="busy" class="size-5 rounded-full border-2 border-zinc-300 border-t-emerald-600 motion-safe:animate-spin" aria-hidden="true"></span>
                    </button>
                </div>
                @error('price')
                    <p class="mt-1 text-sm font-medium text-red-700">{{ $message }}</p>
                @enderror
                <p x-show="error" x-text="error" class="mt-1 text-sm font-medium text-red-700"></p>
                @if ($voiceTranscript !== null)
                    <p class="mt-1 text-sm text-zinc-600">Heard: "{{ $voiceTranscript }}"</p>
                @endif
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
