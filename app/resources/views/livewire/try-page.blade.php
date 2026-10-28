<div>
    <h1 class="text-xl font-bold">Try ordering from Rahman Store — the real shop uses WhatsApp.</h1>
    <p class="mt-1 text-sm text-zinc-600">Send a message like a customer would. Nothing here touches real stock.</p>

    @if ($order === null)
        <div class="mt-4 grid grid-cols-3 gap-1 rounded-xl bg-zinc-100 p-1" role="tablist" aria-label="Order input">
            @foreach (['type' => 'Type', 'photo' => 'Photo', 'voice' => 'Voice'] as $key => $label)
                <button type="button" role="tab" aria-selected="{{ $tab === $key ? 'true' : 'false' }}"
                    wire:click="selectTab('{{ $key }}')"
                    class="min-h-[44px] rounded-lg text-sm font-semibold focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-600 {{ $tab === $key ? 'bg-white text-zinc-900 shadow-sm' : 'text-zinc-500 hover:text-zinc-800' }}">
                    {{ $label }}
                </button>
            @endforeach
        </div>

        @if ($tab === 'type')
            <label class="mt-4 block text-sm font-medium" for="try-text">Your message</label>
            <textarea id="try-text" rows="3" wire:model="text" placeholder="2 Tang Orange, 1 ACI salt…"
                class="mt-1 w-full rounded-xl border border-zinc-300 px-3 py-2 focus:border-emerald-600 focus:outline-none"></textarea>
            @error('text') <p class="mt-1 text-sm font-medium text-red-700">{{ $message }}</p> @enderror
            <button type="button" wire:click="submit" wire:loading.attr="disabled"
                class="mt-3 flex min-h-[52px] w-full items-center justify-center rounded-xl bg-emerald-600 px-6 text-lg font-semibold text-white hover:bg-emerald-700 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-600 disabled:opacity-60">
                Send order
            </button>
        @endif

        @if ($tab === 'photo')
            <label class="mt-4 flex min-h-[64px] cursor-pointer items-center justify-center gap-2 rounded-xl bg-emerald-600 px-6 text-lg font-semibold text-white hover:bg-emerald-700 focus-within:outline-2 focus-within:outline-offset-2 focus-within:outline-emerald-600">
                Pick a list photo
                <input type="file" class="sr-only" wire:model="photo" accept="image/*" capture="environment">
            </label>
            <div wire:loading wire:target="photo" class="mt-2 text-sm font-medium text-emerald-700">
                Uploading your photo…
            </div>
            @error('photo') <p class="mt-2 text-sm font-medium text-red-700">{{ $message }}</p> @enderror
            @if ($photo)
                <img src="{{ $photo->temporaryUrl() }}" alt="List photo preview" class="mt-3 max-h-64 w-full rounded-xl border border-zinc-200 object-cover">
                <button type="button" wire:click="submit" wire:loading.attr="disabled"
                    class="mt-3 flex min-h-[52px] w-full items-center justify-center rounded-xl bg-emerald-600 px-6 text-lg font-semibold text-white hover:bg-emerald-700 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-600 disabled:opacity-60">
                    Send photo
                </button>
            @endif
        @endif

        @if ($tab === 'voice')
            <div class="mt-4 rounded-xl border border-zinc-200 p-4" x-data="tryRecorder()" wire:ignore>
                <template x-if="!supported">
                    <p class="text-sm font-medium text-red-700">Voice recording needs a browser with microphone access, such as Chrome or Safari.</p>
                </template>
                <div x-show="supported" class="flex items-center gap-3">
                    <button type="button" @click="toggle()" :disabled="uploading"
                        class="flex min-h-[52px] min-w-[120px] items-center justify-center rounded-xl bg-emerald-600 px-6 text-lg font-semibold text-white hover:bg-emerald-700 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-600 disabled:opacity-60"
                        x-text="recording ? 'Stop' : 'Record'"></button>
                    <p class="text-2xl font-semibold tabular-nums" aria-label="Recording time"><span x-text="clock"></span><span class="text-base font-normal text-zinc-500"> / 0:30</span></p>
                </div>
                <p class="mt-2 text-sm text-zinc-600" x-show="supported" x-text="hint"></p>
            </div>
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
            @error('audio') <p class="mt-2 text-sm font-medium text-red-700">{{ $message }}</p> @enderror
            @if ($audio)
                <audio controls src="{{ $audio->temporaryUrl() }}" class="mt-3 w-full"></audio>
                <button type="button" wire:click="submit" wire:loading.attr="disabled"
                    class="mt-3 flex min-h-[52px] w-full items-center justify-center rounded-xl bg-emerald-600 px-6 text-lg font-semibold text-white hover:bg-emerald-700 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-600 disabled:opacity-60">
                    Send voice note
                </button>
            @endif
        @endif

        <div wire:loading wire:target="submit" class="mt-2 text-sm font-medium text-emerald-700">
            Got it, checking the shelf…
        </div>
        @error('form') <p class="mt-2 text-sm font-medium text-red-700">{{ $message }}</p> @enderror
    @else
        <div class="mt-4 space-y-3 rounded-xl bg-zinc-100 p-3">
            <div class="ml-auto w-fit max-w-[85%] rounded-2xl rounded-br-md bg-emerald-600 px-4 py-2 text-white">
                @if ($order->source_type === 'photo' && $photo)
                    <img src="{{ $photo->temporaryUrl() }}" alt="Your list photo" class="max-h-56 rounded-lg object-cover">
                @elseif ($order->source_type === 'voice' && $audio)
                    <audio controls src="{{ $audio->temporaryUrl() }}" class="w-full min-w-52"></audio>
                    @if ($order->transcript)
                        <p class="mt-1 text-sm text-emerald-50">{{ $order->transcript }}</p>
                    @endif
                @else
                    <p class="whitespace-pre-line">{{ $this->text }}</p>
                @endif
            </div>

            <div class="mr-auto w-fit max-w-[95%] rounded-2xl rounded-bl-md border border-zinc-200 bg-white px-4 py-3 shadow-sm">
                <p class="text-sm font-semibold text-zinc-500">Rahman Store</p>
                @forelse ($invoice['lines'] as $line)
                    <p class="mt-1 font-medium">{{ $line['text'] }}</p>
                    @if ($line['note'])
                        <p class="text-sm text-zinc-600">{{ $line['note'] }}</p>
                    @endif
                @empty
                    <p class="mt-1 font-medium">Nothing matched on the shelf.</p>
                @endforelse
                <p class="mt-2 border-t border-zinc-200 pt-2 font-bold">Total: {{ $invoice['total'] }}</p>
                @if ($invoice['unavailable'] !== [])
                    <p class="mt-2 text-sm"><span class="font-semibold">Not available:</span> {{ implode(', ', $invoice['unavailable']) }}</p>
                @endif
                @foreach ($invoice['ambiguous'] as $item)
                    <p class="mt-2 text-sm"><span class="font-semibold">Did you mean … “{{ $item['requested'] }}”?</span><br>{{ implode(' / ', $item['options']) }}</p>
                @endforeach
                @if (! $simulated)
                    <button type="button" wire:click="confirmSimulated"
                        class="mt-3 flex min-h-[48px] w-full items-center justify-center rounded-xl bg-emerald-600 px-6 font-semibold text-white hover:bg-emerald-700 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-600">
                        Confirm
                    </button>
                @endif
            </div>

            @if ($simulation !== null)
                <div class="mr-auto w-fit max-w-[95%] rounded-2xl rounded-bl-md border border-zinc-200 bg-white px-4 py-3 shadow-sm">
                    <p class="font-medium">Order #{{ $order->id }} confirmed (simulated). Stock unchanged:</p>
                    @foreach ($simulation as $row)
                        <p class="mt-1 text-sm">Stock: {{ $row['name'] }} {{ $row['before'] }} → {{ $row['after'] }}</p>
                    @endforeach
                </div>
            @endif
        </div>

        <button type="button" wire:click="newOrder"
            class="mt-4 flex min-h-[48px] w-full items-center justify-center rounded-xl border border-zinc-300 px-6 font-semibold text-zinc-700 hover:bg-zinc-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-600">
            ← Start a new order
        </button>
    @endif
</div>
