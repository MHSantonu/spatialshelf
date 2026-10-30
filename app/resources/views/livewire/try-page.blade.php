<div>
    <x-ui.page-heading title="Try ordering from Mahadi Store — the real shop uses WhatsApp." sub="Send a message like a customer would. Nothing here touches real stock." />

    @if ($order === null)
        <div class="mt-4 grid grid-cols-3 gap-1 rounded-xl bg-stone-100 p-1" role="tablist" aria-label="Order input">
            <button type="button" role="tab" aria-selected="{{ $tab === 'type' ? 'true' : 'false' }}"
                wire:click="selectTab('type')"
                class="flex min-h-[60px] flex-col items-center justify-center gap-1 rounded-lg text-base font-semibold {{ $tab === 'type' ? 'bg-white text-stone-900 shadow-sm' : 'text-stone-500 hover:text-stone-800' }}">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="size-6" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M8.625 12a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm0 0H8.25m4.125 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm0 0H12m4.125 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm0 0h-.375M21 12c0 4.556-4.03 8.25-9 8.25a9.764 9.764 0 0 1-2.555-.337A5.972 5.972 0 0 1 5.41 20.97a5.969 5.969 0 0 1-.474-.065 4.48 4.48 0 0 0 .978-2.025c.09-.457-.133-.901-.467-1.226C3.93 16.178 3 14.189 3 12c0-4.556 4.03-8.25 9-8.25s9 3.694 9 8.25Z" />
                </svg>
                Type
            </button>
            <button type="button" role="tab" aria-selected="{{ $tab === 'photo' ? 'true' : 'false' }}"
                wire:click="selectTab('photo')"
                class="flex min-h-[60px] flex-col items-center justify-center gap-1 rounded-lg text-base font-semibold {{ $tab === 'photo' ? 'bg-white text-stone-900 shadow-sm' : 'text-stone-500 hover:text-stone-800' }}">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="size-6" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6.827 6.175A2.31 2.31 0 0 1 5.186 7.23c-.38.054-.757.112-1.134.175C2.999 7.58 2.25 8.507 2.25 9.574V18a2.25 2.25 0 0 0 2.25 2.25h15A2.25 2.25 0 0 0 21.75 18V9.574c0-1.067-.75-1.994-1.802-2.169a47.865 47.865 0 0 0-1.134-.175 2.31 2.31 0 0 1-1.64-1.055l-.822-1.316a2.192 2.192 0 0 0-1.736-1.039 48.774 48.774 0 0 0-5.232 0 2.192 2.192 0 0 0-1.736 1.039l-.821 1.316Z" />
                    <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 12.75a4.5 4.5 0 1 1-9 0 4.5 4.5 0 0 1 9 0Z" />
                </svg>
                Photo
            </button>
            <button type="button" role="tab" aria-selected="{{ $tab === 'voice' ? 'true' : 'false' }}"
                wire:click="selectTab('voice')"
                class="flex min-h-[60px] flex-col items-center justify-center gap-1 rounded-lg text-base font-semibold {{ $tab === 'voice' ? 'bg-white text-stone-900 shadow-sm' : 'text-stone-500 hover:text-stone-800' }}">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="size-6" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 18.75a6 6 0 0 0 6-6v-1.5m-6 7.5a6 6 0 0 1-6-6v-1.5m6 7.5v3.75m-3.75 0h7.5M12 15.75a3 3 0 0 1-3-3V4.5a3 3 0 1 1 6 0v8.25a3 3 0 0 1-3 3Z" />
                </svg>
                Voice
            </button>
        </div>

        @if ($tab === 'type')
            <label class="label mt-4" for="try-text">Your message</label>
            <textarea id="try-text" rows="3" wire:model="text" placeholder="2 Tang Orange, 1 ACI salt…"
                class="textarea mt-1"></textarea>
            @error('text') <p class="error-text">{{ $message }}</p> @enderror
            <x-ui.button wire:click="submit" wire:loading.attr="disabled" size="lg" class="mt-3 w-full">
                Send order
            </x-ui.button>
        @endif

        @if ($tab === 'photo')
            <label class="btn btn-primary btn-lg mt-4 w-full cursor-pointer">
                Pick a list photo
                <input type="file" class="sr-only" wire:model="photo" accept="image/*" capture="environment">
            </label>
            <div wire:loading wire:target="photo" class="mt-3 flex items-center gap-2 text-base font-medium text-leaf-700">
                <span class="size-5 shrink-0 rounded-full border-2 border-stone-300 border-t-leaf-700 motion-safe:animate-spin" aria-hidden="true"></span>
                Uploading your photo…
            </div>
            @error('photo') <p class="error-text">{{ $message }}</p> @enderror
            @if ($photo)
                <img src="{{ $photo->temporaryUrl() }}" alt="List photo preview" class="mt-3 max-h-64 w-full rounded-xl border border-stone-200 object-cover">
                <x-ui.button wire:click="submit" wire:loading.attr="disabled" size="lg" class="mt-3 w-full">
                    Send photo
                </x-ui.button>
            @endif
        @endif

        @if ($tab === 'voice')
            <x-ui.card class="mt-4" x-data="tryRecorder()" wire:ignore>
                <template x-if="!supported">
                    <p class="text-base font-medium text-red-700">Voice recording needs a browser with microphone access, such as Chrome or Safari.</p>
                </template>
                <div x-show="supported" class="flex items-center gap-3">
                    <button type="button" @click="toggle()" :disabled="uploading"
                        class="btn btn-primary min-w-[120px]"
                        x-text="recording ? 'Stop' : 'Record'"></button>
                    <p class="text-2xl font-semibold tabular-nums text-stone-900" aria-label="Recording time"><span x-text="clock"></span><span class="text-base font-normal text-stone-500"> / 0:30</span></p>
                </div>
                <p class="mt-2 text-base text-stone-600" x-show="supported" x-text="hint"></p>
            </x-ui.card>
            <script>
                function tryRecorder() {
                    const MAX_SECONDS = 30;
                    return {
                        supported: !!(window.MediaRecorder && navigator.mediaDevices && navigator.mediaDevices.getUserMedia),
                        recording: false,
                        uploading: false,
                        uploaded: false,
                        seconds: 0,
                        timer: null,
                        recorder: null,
                        stream: null,
                        chunks: [],
                        hint: 'Up to 30 seconds, like a WhatsApp voice note.',
                        get clock() {
                            const total = Math.floor(this.seconds);
                            return Math.floor(total / 60) + ':' + String(total % 60).padStart(2, '0');
                        },
                        async toggle() {
                            if (this.recording) {
                                this.stop();
                            } else {
                                await this.start();
                            }
                        },
                        async start() {
                            this.hint = '';
                            this.uploaded = false;
                            let stream;
                            try {
                                stream = await navigator.mediaDevices.getUserMedia({ audio: true });
                            } catch (error) {
                                this.hint = 'Microphone unavailable. Check the browser permission and try again.';
                                return;
                            }
                            this.stream = stream;
                            const mime = ['audio/webm', 'audio/mp4', 'audio/ogg'].find((type) => {
                                try {
                                    return MediaRecorder.isTypeSupported(type);
                                } catch (error) {
                                    return false;
                                }
                            }) || '';
                            this.chunks = [];
                            this.recorder = new MediaRecorder(stream, mime === '' ? undefined : { mimeType: mime });
                            this.recorder.ondataavailable = (event) => {
                                if (event.data && event.data.size > 0) {
                                    this.chunks.push(event.data);
                                }
                            };
                            this.recorder.onstop = () => this.finish();
                            this.seconds = 0;
                            this.recording = true;
                            this.recorder.start();
                            const started = Date.now();
                            this.timer = setInterval(() => {
                                this.seconds = (Date.now() - started) / 1000;
                                if (this.seconds >= MAX_SECONDS) {
                                    this.stop();
                                }
                            }, 250);
                        },
                        stop() {
                            if (this.timer) {
                                clearInterval(this.timer);
                                this.timer = null;
                            }
                            if (this.recorder && this.recorder.state !== 'inactive') {
                                this.recorder.stop();
                            }
                            if (this.stream) {
                                this.stream.getTracks().forEach((track) => track.stop());
                                this.stream = null;
                            }
                            this.recording = false;
                        },
                        finish() {
                            const type = this.recorder && this.recorder.mimeType
                                ? this.recorder.mimeType.split(';')[0]
                                : 'audio/webm';
                            const ext = type === 'audio/mp4' ? 'mp4' : (type === 'audio/ogg' ? 'ogg' : 'webm');
                            const file = new File(this.chunks, 'note.' + ext, { type });
                            this.uploading = true;
                            this.hint = 'Uploading…';
                            this.$wire.upload('audio', file, () => {
                                this.uploading = false;
                                this.uploaded = true;
                                this.hint = 'Recorded ' + this.clock + '. Record again or send it.';
                            }, () => {
                                this.uploading = false;
                                this.hint = 'Upload failed. Please record again.';
                            });
                        },
                    };
                }
            </script>
            @error('audio') <p class="error-text">{{ $message }}</p> @enderror
            @if ($audio)
                <audio controls src="{{ $audio->temporaryUrl() }}" class="mt-3 w-full"></audio>
                <x-ui.button wire:click="submit" wire:loading.attr="disabled" size="lg" class="mt-3 w-full">
                    Send voice note
                </x-ui.button>
            @endif
        @endif

        <div wire:loading wire:target="submit" class="mt-3 flex items-center gap-2 text-base font-medium text-leaf-700">
            <span class="size-5 shrink-0 rounded-full border-2 border-stone-300 border-t-leaf-700 motion-safe:animate-spin" aria-hidden="true"></span>
            Got it, checking the shelf…
        </div>
        @error('form') <p class="error-text">{{ $message }}</p> @enderror
    @else
        <div class="mt-4 space-y-3 rounded-xl bg-stone-100 p-3">
            <div class="ml-auto w-fit max-w-[85%] rounded-2xl rounded-br-md bg-leaf-700 px-4 py-2 text-white">
                @if ($order->source_type === 'photo' && $photo)
                    <img src="{{ $photo->temporaryUrl() }}" alt="Your list photo" class="max-h-56 rounded-lg object-cover">
                @elseif ($order->source_type === 'voice' && $audio)
                    <audio controls src="{{ $audio->temporaryUrl() }}" class="w-full min-w-52"></audio>
                    @if ($order->transcript)
                        <p class="mt-1 text-base text-leaf-50">{{ $order->transcript }}</p>
                    @endif
                @else
                    <p class="whitespace-pre-line text-base">{{ $this->text }}</p>
                @endif
            </div>

            <div class="mr-auto w-full max-w-[95%] overflow-hidden rounded-2xl rounded-bl-md border border-stone-200 bg-white shadow-card">
                <div class="px-4 pt-3">
                    <p class="text-sm font-semibold text-stone-500">Mahadi Store</p>
                    <div class="mt-2 rounded-xl bg-stone-50 p-3 ring-1 ring-stone-200">
                        @forelse ($invoice['lines'] as $line)
                            @php($parts = explode(' — ', $line['text']))
                            @php($linePrice = count($parts) > 1 ? array_pop($parts) : null)
                            @php($lineName = $linePrice === null ? $line['text'] : implode(' — ', $parts))
                            <div class="flex items-baseline justify-between gap-3 py-1">
                                <p class="min-w-0 text-base font-medium text-stone-900">{{ $lineName }}</p>
                                @if ($linePrice !== null)
                                    <p class="price shrink-0 text-base text-stone-900">{{ $linePrice }}</p>
                                @endif
                            </div>
                            @if ($line['note'])
                                <p class="pb-1 text-base text-stone-600">{{ $line['note'] }}</p>
                            @endif
                        @empty
                            <p class="text-base font-medium text-stone-900">Nothing matched on the shelf.</p>
                        @endforelse
                        <p class="price mt-1 border-t border-stone-200 pt-2 text-right text-lg text-stone-900">Total: {{ $invoice['total'] }}</p>
                    </div>
                    @if ($invoice['unavailable'] !== [])
                        <p class="mt-2 text-base text-stone-800"><span class="font-semibold">Not available:</span> {{ implode(', ', $invoice['unavailable']) }}</p>
                    @endif
                    @foreach ($invoice['ambiguous'] as $item)
                        <p class="mt-2 text-base text-stone-800"><span class="font-semibold">Did you mean … “{{ $item['requested'] }}”?</span><br>{{ implode(' / ', $item['options']) }}</p>
                    @endforeach
                </div>
                @if (! $simulated)
                    <button type="button" wire:click="confirmSimulated"
                        class="mt-3 flex min-h-[52px] w-full items-center justify-center border-t border-stone-200 bg-white text-base font-semibold text-leaf-700 hover:bg-stone-50">
                        Confirm
                    </button>
                @else
                    <div class="pb-3"></div>
                @endif
            </div>

            @if ($simulation !== null)
                <div class="mr-auto w-fit max-w-[95%] rounded-2xl rounded-bl-md border border-stone-200 bg-white px-4 py-3 shadow-card">
                    <p class="text-base font-medium text-stone-900">Order #{{ $order->id }} confirmed (simulated). Stock unchanged:</p>
                    @foreach ($simulation as $row)
                        <p class="tnum mt-1 text-base text-stone-600">Stock: {{ $row['name'] }} {{ $row['before'] }} → {{ $row['after'] }}</p>
                    @endforeach
                </div>
            @endif
        </div>

        <x-ui.button variant="secondary" wire:click="newOrder" class="mt-4 w-full">
            ← Start a new order
        </x-ui.button>
    @endif
</div>
