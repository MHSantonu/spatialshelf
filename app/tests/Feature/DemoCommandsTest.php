<?php

namespace Tests\Feature;

use App\Console\Commands\DemoResetCommand;
use App\Enums\ConversationState;
use App\Enums\OrderStatus;
use App\Enums\ScanItemDecision;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\Scan;
use App\Models\ScanItem;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DemoCommandsTest extends TestCase
{
    use RefreshDatabase;

    private ?string $stockBackup = null;

    private bool $stockExisted = false;

    protected function setUp(): void
    {
        parent::setUp();
        $file = DemoResetCommand::stockFilePath();
        $this->stockExisted = is_file($file);
        $this->stockBackup = $this->stockExisted ? (string) file_get_contents($file) : null;
        if ($this->stockExisted) {
            @unlink($file);
        }
    }

    protected function tearDown(): void
    {
        $file = DemoResetCommand::stockFilePath();
        if ($this->stockExisted && $this->stockBackup !== null) {
            file_put_contents($file, $this->stockBackup);
        } else {
            @unlink($file);
        }
        parent::tearDown();
    }

    public function test_snapshot_writes_current_stock_to_demo_stock_json(): void
    {
        $shop = Shop::factory()->create();
        $first = Product::factory()->create(['shop_id' => $shop->id, 'stock_qty' => 10]);
        $second = Product::factory()->create(['shop_id' => $shop->id, 'stock_qty' => 0]);

        $this->artisan('demo:snapshot')
            ->expectsOutputToContain('2 products')
            ->assertExitCode(0);

        $data = json_decode((string) file_get_contents(DemoResetCommand::stockFilePath()), true);
        $this->assertSame(10, $data['products'][(string) $first->id]);
        $this->assertSame(0, $data['products'][(string) $second->id]);
        $this->assertArrayHasKey('taken_at', $data);
    }

    public function test_reset_wipes_demo_data_and_files_but_keeps_shop_and_users(): void
    {
        Storage::fake('shared');
        $shop = Shop::factory()->create();
        $user = User::factory()->create();
        $product = Product::factory()->create(['shop_id' => $shop->id]);
        ProductImage::create(['product_id' => $product->id, 'crop_path' => 'scans/9/crops/a.jpg']);
        $scan = Scan::create(['shop_id' => $shop->id, 'video_path' => 'uploads/scan.mp4']);
        ScanItem::create([
            'scan_id' => $scan->id, 'crop_path' => "scans/{$scan->id}/crops/kf0_0.jpg",
            'decision' => ScanItemDecision::Pending,
        ]);
        Storage::disk('shared')->put("scans/{$scan->id}/crops/kf0_0.jpg", 'fake');
        Storage::disk('shared')->put('uploads/scan.mp4', 'fake-video');
        $customer = Customer::factory()->create(['shop_id' => $shop->id]);
        Conversation::create(['customer_id' => $customer->id, 'state' => ConversationState::Idle]);
        Storage::disk('shared')->put('wa-media/note.ogg', 'fake-audio');
        Order::factory()->create([
            'shop_id' => $shop->id, 'customer_id' => $customer->id, 'status' => OrderStatus::Confirmed,
            'source_type' => 'voice', 'raw_input_path' => 'wa-media/note.ogg',
        ]);

        $this->artisan('demo:reset')->assertExitCode(0);

        $this->assertSame(0, Order::query()->count());
        $this->assertSame(0, OrderLine::query()->count());
        $this->assertSame(0, Conversation::query()->count());
        $this->assertSame(0, Customer::query()->count());
        $this->assertSame(0, Scan::query()->count());
        $this->assertSame(0, ScanItem::query()->count());
        $this->assertSame(0, Product::query()->count());
        $this->assertSame(0, ProductImage::query()->count());
        $this->assertFalse(Storage::disk('shared')->exists("scans/{$scan->id}/crops/kf0_0.jpg"));
        $this->assertFalse(Storage::disk('shared')->exists('uploads/scan.mp4'));
        $this->assertFalse(Storage::disk('shared')->exists('wa-media/note.ogg'));
        $this->assertTrue(Shop::query()->whereKey($shop->id)->exists());
        $this->assertTrue(User::query()->whereKey($user->id)->exists());
    }

    public function test_reset_keep_catalog_restores_stock_from_the_snapshot(): void
    {
        Storage::fake('shared');
        $shop = Shop::factory()->create();
        $product = Product::factory()->create(['shop_id' => $shop->id, 'stock_qty' => 10]);
        $image = ProductImage::create(['product_id' => $product->id, 'crop_path' => 'scans/9/crops/a.jpg']);
        $scan = Scan::create(['shop_id' => $shop->id, 'video_path' => 'uploads/scan.mp4']);
        Storage::disk('shared')->put("scans/{$scan->id}/clip.mp4", 'fake');
        $this->artisan('demo:snapshot')->assertExitCode(0);

        // Simulate a take: stock sells down, an order and a rescan pile up.
        $product->update(['stock_qty' => 3]);
        Order::factory()->create(['shop_id' => $shop->id, 'status' => OrderStatus::Confirmed]);

        $this->artisan('demo:reset', ['--keep-catalog' => true])->assertExitCode(0);

        $this->assertSame(10, $product->fresh()->stock_qty);
        $this->assertTrue(ProductImage::query()->whereKey($image->id)->exists());
        $this->assertSame(0, Order::query()->count());
        $this->assertSame(0, Scan::query()->count());
        $this->assertFalse(Storage::disk('shared')->exists("scans/{$scan->id}/clip.mp4"));
    }

    public function test_reset_keep_catalog_without_a_snapshot_warns_and_keeps_counts(): void
    {
        $shop = Shop::factory()->create();
        $product = Product::factory()->create(['shop_id' => $shop->id, 'stock_qty' => 3]);
        Order::factory()->create(['shop_id' => $shop->id, 'status' => OrderStatus::Confirmed]);

        $this->artisan('demo:reset', ['--keep-catalog' => true])
            ->expectsOutputToContain('No storage/demo-stock.json found')
            ->assertExitCode(0);

        $this->assertSame(3, $product->fresh()->stock_qty);
        $this->assertSame(0, Order::query()->count());
    }

    public function test_reset_refuses_to_run_in_production(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        $shop = Shop::factory()->create();
        Order::factory()->create(['shop_id' => $shop->id]);

        $this->artisan('demo:reset')
            ->expectsOutputToContain('refuses to run in production')
            ->assertExitCode(1);

        $this->assertSame(1, Order::query()->count());
    }
}
