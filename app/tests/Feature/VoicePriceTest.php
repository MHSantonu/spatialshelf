<?php

namespace Tests\Feature;

use App\Enums\ScanItemDecision;
use App\Models\Scan;
use App\Models\ScanItem;
use App\Models\Shop;
use App\Models\User;
use FilesystemIterator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

class VoicePriceTest extends TestCase
{
    use RefreshDatabase;

    protected string $sharedRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sharedRoot = sys_get_temp_dir().'/voice-price-'.uniqid();
        mkdir($this->sharedRoot, recursive: true);
        config([
            'spatialshelf.shared_storage_path' => $this->sharedRoot,
            'filesystems.disks.shared.root' => $this->sharedRoot,
            'spatialshelf.model_api_key' => 'test-key',
            'spatialshelf.worker_url' => 'http://worker.test',
            'spatialshelf.meta_base_url' => 'http://meta.test',
        ]);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->sharedRoot)) {
            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($this->sharedRoot, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($files as $file) {
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
            rmdir($this->sharedRoot);
        }

        parent::tearDown();
    }

    public function test_guests_cannot_post_voice(): void
    {
        $shop = Shop::factory()->create();
        $scan = Scan::create(['shop_id' => $shop->id, 'video_path' => 'uploads/a.mp4']);
        $item = $this->makeItem($scan, 'Tang Orange');

        $audio = ['audio' => UploadedFile::fake()->create('price.webm', 100, 'audio/webm')];
        $this->post(route('scan-items.voice', $item), $audio)->assertRedirect(route('login'));
        $this->post(route('scans.voice-all', $scan), $audio)->assertRedirect(route('login'));
    }

    public function test_english_number_words_become_a_price(): void
    {
        $this->assertVoicePrice(
            'six hundred fifty',
            ['price' => 650, 'size_value' => null, 'size_unit' => 'unknown', 'confidence' => 0.95],
            ['price' => 650, 'size_value' => null, 'size_unit' => 'unknown'],
        );
    }

    public function test_bangla_number_words_become_a_price(): void
    {
        $this->assertVoicePrice(
            'chhoy sho ponchash',
            ['price' => 650, 'size_value' => null, 'size_unit' => 'unknown', 'confidence' => 0.9],
            ['price' => 650, 'size_value' => null, 'size_unit' => 'unknown'],
        );
    }

    public function test_digits_with_taka_become_a_price(): void
    {
        $this->assertVoicePrice(
            '650 taka',
            ['price' => 650, 'size_value' => null, 'size_unit' => 'unknown', 'confidence' => 0.99],
            ['price' => 650, 'size_value' => null, 'size_unit' => 'unknown'],
        );
    }

    public function test_size_and_price_split_into_both_fields(): void
    {
        $this->assertVoicePrice(
            'half kg 120',
            ['price' => 120, 'size_value' => 0.5, 'size_unit' => 'kg', 'confidence' => 0.92],
            ['price' => 120, 'size_value' => 0.5, 'size_unit' => 'kg'],
        );
    }

    public function test_unsure_model_returns_nulls_with_transcript(): void
    {
        $this->assertVoicePrice(
            ' noise ',
            ['price' => null, 'size_value' => null, 'size_unit' => 'unknown', 'confidence' => 0.2],
            ['price' => null, 'size_value' => null, 'size_unit' => 'unknown'],
        );
    }

    public function test_empty_transcript_is_422(): void
    {
        $shop = Shop::factory()->create();
        $scan = Scan::create(['shop_id' => $shop->id, 'video_path' => 'uploads/a.mp4']);
        $item = $this->makeItem($scan, 'Tang Orange');
        $this->fakeVoiceChain('   ', ['price' => null, 'size_value' => null, 'size_unit' => 'unknown', 'confidence' => null]);
        $this->actingAs(User::factory()->create());

        $this->postJson(route('scan-items.voice', $item), [
            'audio' => UploadedFile::fake()->create('price.webm', 100, 'audio/webm'),
        ])->assertStatus(422)->assertJsonPath('message', 'No speech detected — hold the button and try again.');
    }

    public function test_shared_disk_stays_world_readable_for_the_worker(): void
    {
        // php-fpm uploads and the shelf-owned Python worker share this disk;
        // private visibility would create 0700 directories the worker cannot read.
        $this->assertSame('public', config('filesystems.disks.shared.visibility'));
    }

    public function test_non_audio_upload_is_rejected(): void
    {
        $shop = Shop::factory()->create();
        $scan = Scan::create(['shop_id' => $shop->id, 'video_path' => 'uploads/a.mp4']);
        $item = $this->makeItem($scan, 'Tang Orange');
        $this->actingAs(User::factory()->create());

        $this->postJson(route('scan-items.voice', $item), [
            'audio' => UploadedFile::fake()->create('price.txt', 100, 'text/plain'),
        ])->assertStatus(422)->assertJsonValidationErrors('audio');
    }

    public function test_phone_webm_recording_sniffed_as_video_webm_is_accepted(): void
    {
        $shop = Shop::factory()->create();
        $scan = Scan::create(['shop_id' => $shop->id, 'video_path' => 'uploads/a.mp4']);
        $item = $this->makeItem($scan, 'Tang Orange');
        $this->fakeVoiceChain(
            '650 taka',
            ['price' => 650, 'size_value' => null, 'size_unit' => 'unknown', 'confidence' => 0.99]
        );
        $this->actingAs(User::factory()->create());

        // Minimal EBML header with a WebM DocType: finfo reports video/webm,
        // exactly like a phone MediaRecorder upload. A real UploadedFile is
        // required here — fakes report the mime they are constructed with.
        $path = $this->sharedRoot.'/note.webm';
        file_put_contents($path, hex2bin('1a45dfa3844282847765626d'));
        $audio = new UploadedFile($path, 'note.webm', 'audio/webm', UPLOAD_ERR_OK, true);
        $this->assertSame('video/webm', $audio->getMimeType());

        $this->postJson(route('scan-items.voice', $item), ['audio' => $audio])
            ->assertOk()
            ->assertJsonPath('price', 650);
    }

    public function test_voice_all_maps_prices_to_pending_cards_by_id(): void
    {
        $shop = Shop::factory()->create();
        $scan = Scan::create(['shop_id' => $shop->id, 'video_path' => 'uploads/a.mp4']);
        $horlicks = $this->makeItem($scan, 'Horlicks', 1);
        $tang = $this->makeItem($scan, 'Tang Orange', 2);
        $done = $this->makeItem($scan, 'Ketchup', 3);
        $done->update(['decision' => ScanItemDecision::Confirmed]);
        $this->fakeVoiceChain(
            'Horlicks six fifty, Tang four fifty',
            ['items' => [
                ['item_id' => (string) $horlicks->id, 'price' => 650, 'size_value' => null, 'size_unit' => 'unknown', 'confidence' => 0.9],
                ['item_id' => (string) $tang->id, 'price' => 450, 'size_value' => 0.5, 'size_unit' => 'kg', 'confidence' => 0.88],
                ['item_id' => 'none', 'price' => 10, 'size_value' => null, 'size_unit' => 'unknown', 'confidence' => 0.3],
            ]]
        );
        $this->actingAs(User::factory()->create());

        $response = $this->postJson(route('scans.voice-all', $scan), [
            'audio' => UploadedFile::fake()->create('prices.webm', 100, 'audio/webm'),
        ]);

        $response->assertOk()
            ->assertJsonPath('transcript', 'Horlicks six fifty, Tang four fifty')
            ->assertJsonCount(2, 'items')
            ->assertJsonPath('items.0', [
                'item_id' => $horlicks->id, 'price' => 650,
                'size_value' => null, 'size_unit' => 'unknown', 'confidence' => 0.9,
            ])
            ->assertJsonPath('items.1.item_id', $tang->id)
            ->assertJsonPath('items.1.size_value', 0.5);
        $recorded = Http::recorded(fn (Request $request) => $request->url() === 'http://meta.test/chat/completions');
        $payload = $recorded->values()->first()[0]->data();
        $this->assertSame([(string) $horlicks->id, (string) $tang->id, 'none'], $payload['response_format']['json_schema']['schema']['properties']['items']['items']['properties']['item_id']['enum']);
        $this->assertStringContainsString("{$horlicks->id}: Horlicks", $payload['messages'][0]['content']);
        $this->assertStringNotContainsString('Ketchup', $payload['messages'][0]['content']);
    }

    public function test_voice_all_with_no_pending_cards_is_422(): void
    {
        $shop = Shop::factory()->create();
        $scan = Scan::create(['shop_id' => $shop->id, 'video_path' => 'uploads/a.mp4']);
        $this->actingAs(User::factory()->create());

        $this->postJson(route('scans.voice-all', $scan), [
            'audio' => UploadedFile::fake()->create('prices.webm', 100, 'audio/webm'),
        ])->assertStatus(422)->assertJsonPath('message', 'No pending cards to price.');
    }

    /**
     * @param  array{price: float|null, size_value: float|null, size_unit: string}  $expected
     */
    private function assertVoicePrice(string $transcript, array $model, array $expected): void
    {
        $shop = Shop::factory()->create();
        $scan = Scan::create(['shop_id' => $shop->id, 'video_path' => 'uploads/a.mp4']);
        $item = $this->makeItem($scan, 'Tang Orange');
        $this->fakeVoiceChain($transcript, $model);
        $this->actingAs(User::factory()->create());

        $response = $this->postJson(route('scan-items.voice', $item), [
            'audio' => UploadedFile::fake()->create('price.webm', 100, 'audio/webm'),
        ]);

        $response->assertOk()
            ->assertJsonPath('transcript', trim($transcript))
            ->assertJsonPath('price', $expected['price'])
            ->assertJsonPath('size_value', $expected['size_value'])
            ->assertJsonPath('size_unit', $expected['size_unit']);
        $keywords = $this->asrKeywords();
        $this->assertContains('Tang Orange', $keywords);
        $this->assertContains('taka', $keywords);
        $this->assertContains('kg', $keywords);
    }

    private function makeItem(Scan $scan, string $name, int $order = 1): ScanItem
    {
        return ScanItem::create([
            'scan_id' => $scan->id,
            'crop_path' => "scans/{$scan->id}/crops/kf0_{$order}.jpg",
            'shelf_row' => 1,
            'shelf_order' => $order,
            'decision' => ScanItemDecision::Pending,
            'extracted' => ['name' => $name, 'size_value' => null, 'size_unit' => 'unknown', 'category' => 'other', 'confidence' => 0.9],
        ]);
    }

    private function fakeVoiceChain(string $transcript, array $chatContent): void
    {
        mkdir($this->sharedRoot.'/voice', recursive: true);
        file_put_contents($this->sharedRoot.'/voice/note.wav', 'fake-wav-bytes');
        Http::fake([
            'http://worker.test/audio/to-wav' => Http::response([
                'audio_path' => 'a.ogg', 'wav_path' => 'voice/note.wav',
                'sample_rate' => 24000, 'channels' => 1,
            ]),
            'http://meta.test/asr/transcribe' => Http::response(['transcript' => $transcript]),
            'http://meta.test/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => json_encode($chatContent)]]],
            ]),
        ]);
    }

    /** @return list<string> */
    private function asrKeywords(): array
    {
        $recorded = Http::recorded(fn (Request $request) => $request->url() === 'http://meta.test/asr/transcribe');
        $this->assertCount(1, $recorded);
        $parts = [];
        foreach ($recorded->values()->first()[0]->data() as $part) {
            $parts[$part['name']] = $part;
        }

        return json_decode($parts['request']['contents'], true)['keywords'];
    }
}
