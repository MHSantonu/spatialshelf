<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Livewire\TryPage;
use App\Models\Order;
use App\Models\Product;
use App\Models\Shop;
use FilesystemIterator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

class TryPageTest extends TestCase
{
    use RefreshDatabase;

    protected string $sharedRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sharedRoot = sys_get_temp_dir().'/try-test-'.uniqid();
        mkdir($this->sharedRoot, recursive: true);
        config([
            'spatialshelf.shared_storage_path' => $this->sharedRoot,
            'filesystems.disks.shared.root' => $this->sharedRoot,
            'spatialshelf.model_api_key' => 'test-key',
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

    public function test_guests_can_open_try_page(): void
    {
        Shop::factory()->create();

        $this->get(route('try'))
            ->assertOk()
            ->assertSee('Try ordering from Mahadi Store — the real shop uses WhatsApp.')
            ->assertSee('Type')
            ->assertSee('Photo')
            ->assertSee('Voice');

        Livewire::test(TryPage::class)
            ->call('selectTab', 'photo')
            ->assertSee('accept="image/*"', false)
            ->assertSee('capture="environment"', false);

        Livewire::test(TryPage::class)
            ->call('selectTab', 'voice')
            ->assertSee('MediaRecorder', false)
            ->assertSee('/ 0:30');
    }

    public function test_photo_tab_offers_take_photo_and_gallery_inputs(): void
    {
        Shop::factory()->create();

        $html = Livewire::test(TryPage::class)->call('selectTab', 'photo')->html();

        $this->assertStringContainsString('Take photo', $html);
        $this->assertStringContainsString('Choose from gallery', $html);
        preg_match_all('/<input[^>]*>/', $html, $matches);
        $photoInputs = array_values(array_filter(
            $matches[0],
            fn (string $tag): bool => str_contains($tag, 'wire:model="photo"')
        ));
        $this->assertCount(2, $photoInputs);
        foreach ($photoInputs as $tag) {
            $this->assertStringContainsString('type="file"', $tag);
            $this->assertStringContainsString('accept="image/*"', $tag);
        }
        $withCapture = array_values(array_filter(
            $photoInputs,
            fn (string $tag): bool => str_contains($tag, 'capture=')
        ));
        $this->assertCount(1, $withCapture);
        $this->assertStringContainsString('capture="environment"', $withCapture[0]);
    }

    public function test_text_submit_creates_simulated_order_and_shows_invoice(): void
    {
        $shop = Shop::factory()->create();
        $tang = $this->tang($shop->id);
        $this->fakeChat([['requested_text' => 'Tang x2', 'qty' => 2, 'product_id' => (string) $tang->id]]);

        Livewire::test(TryPage::class)
            ->set('text', 'Tang x2')
            ->call('submit')
            ->assertHasNoErrors()
            ->assertSee('Tang x2')
            ->assertSee('2 × Tang Orange 500g')
            ->assertSee('৳900')
            ->assertSee('Total: ৳900');

        $order = Order::query()->firstOrFail();
        $this->assertSame(OrderStatus::Simulated, $order->status);
        $this->assertSame('text', $order->source_type);
        $this->assertSame('try', $order->context['channel']);
    }

    public function test_photo_submit_stores_and_parses(): void
    {
        $shop = Shop::factory()->create();
        $tang = $this->tang($shop->id);
        $this->fakeChat([['requested_text' => 'Tang', 'qty' => 1, 'product_id' => (string) $tang->id]]);

        Livewire::test(TryPage::class)
            ->set('tab', 'photo')
            ->set('photo', UploadedFile::fake()->image('list.jpg'))
            ->call('submit')
            ->assertHasNoErrors()
            ->assertSee('1 × Tang Orange 500g')
            ->assertSee('৳450');

        $order = Order::query()->firstOrFail();
        $this->assertSame('photo', $order->source_type);
        $this->assertStringStartsWith('try/', $order->raw_input_path);
        $this->assertFileExists($this->sharedRoot.'/'.$order->raw_input_path);
    }

    public function test_voice_submit_transcribes_first(): void
    {
        $shop = Shop::factory()->create();
        $tang = $this->tang($shop->id);
        mkdir($this->sharedRoot.'/voice', recursive: true);
        file_put_contents($this->sharedRoot.'/voice/note.wav', 'fake-wav-bytes');
        $worker = rtrim(config('spatialshelf.worker_url'), '/');
        $meta = rtrim(config('spatialshelf.meta_base_url'), '/');
        Http::fake([
            "{$worker}/audio/to-wav" => Http::response([
                'audio_path' => 'try/note', 'wav_path' => 'voice/note.wav',
                'sample_rate' => 24000, 'channels' => 1,
            ]),
            "{$meta}/asr/transcribe" => Http::response(['transcript' => 'Tang one piece']),
            "{$meta}/chat/completions" => Http::response($this->chatRaw([
                ['requested_text' => 'Tang one piece', 'qty' => 1, 'product_id' => (string) $tang->id],
            ])),
        ]);

        Livewire::test(TryPage::class)
            ->set('tab', 'voice')
            ->set('audio', UploadedFile::fake()->create('note.webm', 100, 'audio/webm'))
            ->call('submit')
            ->assertHasNoErrors()
            ->assertSee('Tang one piece')
            ->assertSee('1 × Tang Orange 500g')
            ->assertSee('৳450');

        $order = Order::query()->firstOrFail();
        $this->assertSame('voice', $order->source_type);
        $this->assertSame('Tang one piece', $order->transcript);
        $this->assertStringStartsWith('try/', $order->raw_input_path);
    }

    public function test_confirm_simulates_without_changing_stock(): void
    {
        $shop = Shop::factory()->create();
        $tang = $this->tang($shop->id, stock: 9);
        $this->fakeChat([['requested_text' => 'Tang', 'qty' => 2, 'product_id' => (string) $tang->id]]);

        $test = Livewire::test(TryPage::class)
            ->set('text', 'Tang')
            ->call('submit');
        $order = Order::query()->firstOrFail();

        $test->call('confirmSimulated')
            ->assertSee("Order #{$order->id}")
            ->assertSee('Stock: Tang Orange 9 → 7');

        $this->assertSame(9, $tang->fresh()->stock_qty);
        $this->assertSame(OrderStatus::Simulated, $order->fresh()->status);
    }

    public function test_ambiguous_and_unavailable_lines_display(): void
    {
        $shop = Shop::factory()->create();
        $tang = $this->tang($shop->id);
        $mango = Product::factory()->create([
            'shop_id' => $shop->id, 'name' => 'Mango Juice', 'size_value' => 250,
            'size_unit' => 'ml', 'price' => 120, 'stock_qty' => 9,
        ]);
        $this->fakeChat([
            [
                'requested_text' => 'mango drink', 'qty' => 1, 'product_id' => 'none',
                'status' => 'ambiguous', 'alternative_ids' => [(string) $tang->id, (string) $mango->id],
            ],
            ['requested_text' => 'Unicorn tears', 'qty' => 1, 'product_id' => 'none', 'status' => 'not_found'],
        ]);

        Livewire::test(TryPage::class)
            ->set('text', 'mango drink and unicorn tears')
            ->call('submit')
            ->assertHasNoErrors()
            ->assertSee('Did you mean …')
            ->assertSee('Tang Orange 500g')
            ->assertSee('Mango Juice 250ml')
            ->assertSee('Not available:')
            ->assertSee('Unicorn tears');
    }

    public function test_capped_note_shows_on_invoice(): void
    {
        $shop = Shop::factory()->create();
        $tang = $this->tang($shop->id, stock: 1);
        $this->fakeChat([['requested_text' => 'Tang', 'qty' => 3, 'product_id' => (string) $tang->id]]);

        Livewire::test(TryPage::class)
            ->set('text', 'Tang x3')
            ->call('submit')
            ->assertHasNoErrors()
            ->assertSee('Only 1 in stock at ৳450 each; wanted 3.');
    }

    public function test_rate_limit_blocks_the_eleventh_submit(): void
    {
        $shop = Shop::factory()->create();
        $tang = $this->tang($shop->id);
        $this->fakeChat([['requested_text' => 'Tang', 'qty' => 1, 'product_id' => (string) $tang->id]]);

        foreach (range(1, 10) as $i) {
            Livewire::test(TryPage::class)
                ->set('text', 'Tang')
                ->call('submit')
                ->assertHasNoErrors();
        }

        Livewire::test(TryPage::class)
            ->set('text', 'Tang')
            ->call('submit')
            ->assertHasErrors('form')
            ->assertSee('Too many tries');

        $this->assertSame(10, Order::query()->count());
    }

    public function test_empty_text_is_rejected(): void
    {
        Shop::factory()->create();

        Livewire::test(TryPage::class)
            ->set('text', '   ')
            ->call('submit')
            ->assertHasErrors('text');

        $this->assertSame(0, Order::query()->count());
    }

    private function tang(int $shopId, int $stock = 10): Product
    {
        return Product::factory()->create([
            'shop_id' => $shopId, 'name' => 'Tang Orange', 'size_value' => 500,
            'size_unit' => 'g', 'price' => 450, 'stock_qty' => $stock,
        ]);
    }

    /** @param  list<array>  $lines */
    private function chatRaw(array $lines): array
    {
        $full = array_map(fn (array $line): array => array_merge([
            'qty_unit' => '', 'status' => 'matched', 'alternative_ids' => [], 'confidence' => 0.9,
        ], $line), $lines);

        return ['choices' => [['message' => ['content' => json_encode(['intent' => 'new_order', 'lines' => $full])]]]];
    }

    /** @param  list<array>  $lines */
    private function fakeChat(array $lines): void
    {
        $meta = rtrim(config('spatialshelf.meta_base_url'), '/');
        Http::fake(["{$meta}/chat/completions" => Http::response($this->chatRaw($lines))]);
    }
}
