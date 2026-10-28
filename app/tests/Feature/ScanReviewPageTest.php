<?php

namespace Tests\Feature;

use App\Enums\ScanItemDecision;
use App\Livewire\ScanReviewPage;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\Scan;
use App\Models\ScanItem;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class ScanReviewPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_login(): void
    {
        $shop = Shop::factory()->create();
        $scan = Scan::create(['shop_id' => $shop->id, 'video_path' => 'uploads/a.mp4']);

        $this->get(route('scans.review', $scan))->assertRedirect(route('login'));
        $this->get("/scans/{$scan->id}/crops/kf0_0.jpg")->assertRedirect(route('login'));
    }

    public function test_renders_first_pending_card_in_shelf_order(): void
    {
        $shop = Shop::factory()->create();
        $product = Product::factory()->create(['shop_id' => $shop->id, 'price' => 45]);
        $scan = Scan::create(['shop_id' => $shop->id, 'video_path' => 'uploads/a.mp4']);
        $this->makeItem($scan, ['shelf_row' => 2, 'extracted' => $this->extracted('Later')]);
        $this->makeItem($scan, [
            'shelf_row' => 1,
            'matched_product_id' => $product->id,
            'extracted' => $this->extracted('Tang Orange', confidence: 0.9),
        ]);
        $this->actingAs(User::factory()->create());

        $this->get(route('scans.review', $scan))
            ->assertOk()
            ->assertSee('Tang Orange')
            ->assertSee('1 / 2')
            ->assertSee('Known')
            ->assertSee('~4 in stock')
            ->assertSee('bg-emerald-500', false);

        Livewire::test(ScanReviewPage::class, ['scan' => $scan])->assertSet('price', '45');
    }

    public function test_price_prefills_from_mrp_digits_without_a_match(): void
    {
        $shop = Shop::factory()->create();
        $scan = Scan::create(['shop_id' => $shop->id, 'video_path' => 'uploads/a.mp4']);
        $this->makeItem($scan, ['extracted' => $this->extracted('Mango drink', mrp: 'MRP ৳45')]);
        $this->actingAs(User::factory()->create());

        Livewire::test(ScanReviewPage::class, ['scan' => $scan])->assertSet('price', '45');
    }

    public function test_confirm_updates_matched_product_price_and_advances(): void
    {
        $shop = Shop::factory()->create();
        $product = Product::factory()->create(['shop_id' => $shop->id, 'price' => 40]);
        $scan = Scan::create(['shop_id' => $shop->id, 'video_path' => 'uploads/a.mp4']);
        $first = $this->makeItem($scan, ['matched_product_id' => $product->id]);
        $this->makeItem($scan, ['shelf_order' => 2, 'extracted' => $this->extracted('Second')]);
        $this->actingAs(User::factory()->create());

        Livewire::test(ScanReviewPage::class, ['scan' => $scan])
            ->set('price', '45')
            ->call('confirm')
            ->assertSee('Second')
            ->assertSee('2 / 2');

        $this->assertSame(ScanItemDecision::Confirmed, $first->fresh()->decision);
        $this->assertSame('45.00', $product->fresh()->price);
        $this->assertSame(1, Product::query()->count());
        $this->assertSame(1, ProductImage::query()->where('product_id', $product->id)->count());
    }

    public function test_confirm_creates_product_and_image_when_unmatched(): void
    {
        $shop = Shop::factory()->create();
        $scan = Scan::create(['shop_id' => $shop->id, 'video_path' => 'uploads/a.mp4']);
        $item = $this->makeItem($scan, ['est_qty' => 6]);
        $this->actingAs(User::factory()->create());

        Livewire::test(ScanReviewPage::class, ['scan' => $scan])
            ->set('price', '45')
            ->call('confirm')
            ->assertSee('All done.');

        $product = Product::query()->firstOrFail();
        $this->assertSame('Tang Orange', $product->name);
        $this->assertSame('45.00', $product->price);
        $this->assertSame(6, $product->stock_qty);
        $this->assertTrue($product->stock_is_estimate);
        $image = ProductImage::query()->firstOrFail();
        $this->assertSame($product->id, $image->product_id);
        $this->assertSame($item->crop_path, $image->crop_path);
        $this->assertSame($product->id, $item->fresh()->matched_product_id);
    }

    public function test_confirm_requires_price_above_zero(): void
    {
        $shop = Shop::factory()->create();
        $scan = Scan::create(['shop_id' => $shop->id, 'video_path' => 'uploads/a.mp4']);
        $item = $this->makeItem($scan);
        $this->actingAs(User::factory()->create());

        Livewire::test(ScanReviewPage::class, ['scan' => $scan])
            ->set('price', '0')
            ->call('confirm')
            ->assertHasErrors('price');

        $this->assertSame(ScanItemDecision::Pending, $item->fresh()->decision);
    }

    public function test_reject_advances_and_all_done_links_to_stock(): void
    {
        $shop = Shop::factory()->create();
        $scan = Scan::create(['shop_id' => $shop->id, 'video_path' => 'uploads/a.mp4']);
        $item = $this->makeItem($scan);
        $this->actingAs(User::factory()->create());

        Livewire::test(ScanReviewPage::class, ['scan' => $scan])
            ->call('reject')
            ->assertSee('All done.')
            ->assertSee(route('stock', absolute: false));

        $this->assertSame(ScanItemDecision::Rejected, $item->fresh()->decision);
    }

    public function test_edit_stages_changes_that_confirm_applies(): void
    {
        $shop = Shop::factory()->create();
        $scan = Scan::create(['shop_id' => $shop->id, 'video_path' => 'uploads/a.mp4']);
        $item = $this->makeItem($scan);
        $this->actingAs(User::factory()->create());

        Livewire::test(ScanReviewPage::class, ['scan' => $scan])
            ->call('startEdit')
            ->set('form.name', 'Sajeeb Mango Juice')
            ->set('form.size_value', '250')
            ->set('form.size_unit', 'ml')
            ->set('form.category', 'drinks')
            ->set('form.qty', '9')
            ->call('saveEdit')
            ->assertSee('Sajeeb Mango Juice');

        // Staged on the card, not yet in the database.
        $this->assertSame('Tang Orange', $item->fresh()->extracted['name']);

        Livewire::test(ScanReviewPage::class, ['scan' => $scan])
            ->call('startEdit')
            ->set('form.name', 'Sajeeb Mango Juice')
            ->set('form.size_value', '250')
            ->set('form.size_unit', 'ml')
            ->set('form.category', 'drinks')
            ->set('form.qty', '9')
            ->call('saveEdit')
            ->set('price', '55')
            ->call('confirm');

        $fresh = $item->fresh();
        $this->assertSame('Sajeeb Mango Juice', $fresh->extracted['name']);
        $this->assertSame(250.0, (float) $fresh->extracted['size_value']);
        $this->assertSame('ml', $fresh->extracted['size_unit']);
        $this->assertSame(9, $fresh->est_qty);
        $product = Product::query()->firstOrFail();
        $this->assertSame('Sajeeb Mango Juice', $product->name);
        $this->assertSame(9, $product->stock_qty);
        $this->assertFalse($product->stock_is_estimate);
    }

    public function test_same_as_merge_sums_facings_and_keeps_better_name_and_larger_crop(): void
    {
        Storage::fake('shared');
        $shop = Shop::factory()->create();
        $scan = Scan::create(['shop_id' => $shop->id, 'video_path' => 'uploads/a.mp4']);
        Storage::disk('shared')->put("scans/{$scan->id}/crops/small.jpg", 'tiny');
        Storage::disk('shared')->put("scans/{$scan->id}/crops/big.jpg", str_repeat('x', 5000));
        $current = $this->makeItem($scan, [
            'crop_path' => "scans/{$scan->id}/crops/small.jpg",
            'facings' => 1,
            'est_qty' => 4,
            'extracted' => $this->extracted('Tang', confidence: 0.9),
        ]);
        $other = $this->makeItem($scan, [
            'shelf_order' => 2,
            'crop_path' => "scans/{$scan->id}/crops/big.jpg",
            'facings' => 2,
            'est_qty' => 8,
            'extracted' => $this->extracted('Tang Orange', confidence: 0.95),
        ]);
        $this->actingAs(User::factory()->create());

        Livewire::test(ScanReviewPage::class, ['scan' => $scan])
            ->call('mergeWith', $other->id)
            ->assertSee('Tang Orange')
            ->assertSee('2 / 2');

        $fresh = $current->fresh();
        $this->assertSame('Tang Orange', $fresh->extracted['name']);
        $this->assertSame("scans/{$scan->id}/crops/big.jpg", $fresh->crop_path);
        $this->assertSame(3, $fresh->facings);
        $this->assertSame(12, $fresh->est_qty);
        $this->assertSame(ScanItemDecision::Merged, $other->fresh()->decision);
    }

    public function test_unclear_card_shows_nearest_hint_with_one_tap_merge(): void
    {
        $shop = Shop::factory()->create();
        $scan = Scan::create(['shop_id' => $shop->id, 'video_path' => 'uploads/a.mp4']);
        $unclear = $this->makeItem($scan, [
            'embedding' => [1, 0, 0],
            'extracted' => $this->extracted('Orange box (brand unclear)', confidence: 0.3),
        ]);
        $near = $this->makeItem($scan, [
            'shelf_order' => 2,
            'embedding' => [0.95, 0.31, 0],
            'extracted' => $this->extracted('Tang Orange'),
        ]);
        $this->makeItem($scan, [
            'shelf_order' => 3,
            'embedding' => [0, 1, 0],
            'extracted' => $this->extracted('Soap Bar'),
        ]);
        $this->actingAs(User::factory()->create());

        Livewire::test(ScanReviewPage::class, ['scan' => $scan])
            ->assertSee('Might be a duplicate of')
            ->assertSee('Tang Orange')
            ->call('mergeWith', $near->id);

        $this->assertSame('Tang Orange', $unclear->fresh()->extracted['name']);
        $this->assertSame(ScanItemDecision::Merged, $near->fresh()->decision);
    }

    public function test_reject_all_unclear_rejects_only_unclear_cards(): void
    {
        $shop = Shop::factory()->create();
        $scan = Scan::create(['shop_id' => $shop->id, 'video_path' => 'uploads/a.mp4']);
        $unclear = $this->makeItem($scan, ['extracted' => $this->extracted('Box (brand unclear)')]);
        $clear = $this->makeItem($scan, ['shelf_order' => 2, 'extracted' => $this->extracted('Tang Orange')]);
        $this->actingAs(User::factory()->create());

        Livewire::test(ScanReviewPage::class, ['scan' => $scan])
            ->assertSee('Reject all unclear (1)')
            ->call('rejectUnclear')
            ->assertSee('Tang Orange');

        $this->assertSame(ScanItemDecision::Rejected, $unclear->fresh()->decision);
        $this->assertSame(ScanItemDecision::Pending, $clear->fresh()->decision);
    }

    public function test_crop_route_serves_and_404s(): void
    {
        Storage::fake('shared');
        $shop = Shop::factory()->create();
        $scan = Scan::create(['shop_id' => $shop->id, 'video_path' => 'uploads/a.mp4']);
        Storage::disk('shared')->put("scans/{$scan->id}/crops/kf0_0.jpg", 'fake-jpg');
        $this->actingAs(User::factory()->create());

        $response = $this->get(route('scans.crop', [$scan, 'kf0_0.jpg']));
        $response->assertOk()->assertHeader('Content-Type', 'image/jpeg');
        $this->assertSame('fake-jpg', file_get_contents($response->baseResponse->getFile()->getPathname()));
        $this->get(route('scans.crop', [$scan, 'missing.jpg']))->assertNotFound();
    }

    private function makeItem(Scan $scan, array $overrides = []): ScanItem
    {
        return ScanItem::create(array_merge([
            'scan_id' => $scan->id,
            'crop_path' => "scans/{$scan->id}/crops/kf0_0.jpg",
            'embedding' => [1, 0, 0],
            'facings' => 1,
            'est_qty' => 4,
            'shelf_row' => 1,
            'shelf_order' => 1,
            'extracted' => $this->extracted('Tang Orange'),
            'decision' => ScanItemDecision::Pending,
        ], $overrides));
    }

    private function extracted(string $name, float $confidence = 0.9, string $mrp = ''): array
    {
        return [
            'name' => $name,
            'brand' => 'Tang',
            'size_value' => 0,
            'size_unit' => 'unknown',
            'category' => 'drinks',
            'mrp_text' => $mrp,
            'confidence' => $confidence,
        ];
    }
}
