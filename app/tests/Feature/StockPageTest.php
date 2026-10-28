<?php

namespace Tests\Feature;

use App\Livewire\StockPage;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class StockPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get(route('stock'))->assertRedirect(route('login'));
    }

    public function test_renders_products_grouped_by_row_with_totals(): void
    {
        $shop = Shop::factory()->create();
        Product::factory()->create([
            'shop_id' => $shop->id, 'name' => 'Tang Orange', 'price' => 45,
            'stock_qty' => 8, 'stock_is_estimate' => true, 'shelf_row' => 2,
        ]);
        Product::factory()->create([
            'shop_id' => $shop->id, 'name' => 'Horlicks Jar', 'price' => 350,
            'stock_qty' => 3, 'stock_is_estimate' => false, 'shelf_row' => 1,
        ]);
        $this->actingAs(User::factory()->create());

        $response = $this->get(route('stock'));

        $response->assertOk()
            ->assertSee('Row 1')
            ->assertSee('Row 2')
            ->assertSee('Tang Orange')
            ->assertSee('~8')
            ->assertSee('৳45', false)
            ->assertSee('2 products · ৳1,410 stock value', false);
        // Exact stock has no tilde: ">3<" appears, "~3" does not.
        $response->assertSee('>3<', false)->assertDontSee('~3');
        $this->assertTrue(
            strpos($response->getContent(), 'Horlicks Jar') < strpos($response->getContent(), 'Tang Orange')
        );
    }

    public function test_search_filters_by_name_and_brand(): void
    {
        $shop = Shop::factory()->create();
        Product::factory()->create(['shop_id' => $shop->id, 'name' => 'Tang Orange', 'brand' => 'Mondelez']);
        Product::factory()->create(['shop_id' => $shop->id, 'name' => 'Horlicks Jar', 'brand' => 'Unilever']);
        $this->actingAs(User::factory()->create());

        Livewire::test(StockPage::class)
            ->set('search', 'horlicks')
            ->assertSee('Horlicks Jar')
            ->assertDontSee('Tang Orange')
            ->set('search', 'mondelez')
            ->assertSee('Tang Orange')
            ->assertDontSee('Horlicks Jar');
    }

    public function test_thumbnail_uses_latest_image_or_initial_placeholder(): void
    {
        Storage::fake('shared');
        $shop = Shop::factory()->create();
        $withImage = Product::factory()->create(['shop_id' => $shop->id]);
        Storage::disk('shared')->put('scans/1/crops/a.jpg', 'fake');
        Storage::disk('shared')->put('scans/1/crops/b.jpg', 'fake');
        $old = ProductImage::create(['product_id' => $withImage->id, 'crop_path' => 'scans/1/crops/a.jpg']);
        $latest = ProductImage::create(['product_id' => $withImage->id, 'crop_path' => 'scans/1/crops/b.jpg']);
        Product::factory()->create(['shop_id' => $shop->id, 'name' => 'No Photo Soap']);
        $this->actingAs(User::factory()->create());

        $response = $this->get(route('stock'));

        $response->assertOk()
            ->assertSee(route('product-images.show', $latest, absolute: false))
            ->assertDontSee(route('product-images.show', $old, absolute: false))
            ->assertSee('>N<', false);
    }

    public function test_editing_price_and_qty_saves_and_clears_estimate(): void
    {
        $shop = Shop::factory()->create();
        $product = Product::factory()->create([
            'shop_id' => $shop->id, 'price' => 45, 'stock_qty' => 8, 'stock_is_estimate' => true,
        ]);
        $this->actingAs(User::factory()->create());

        Livewire::test(StockPage::class)
            ->call('startEdit', $product->id)
            ->set('editPrice', '50')
            ->set('editQty', '7')
            ->call('saveEdit');

        $fresh = $product->fresh();
        $this->assertSame('50.00', $fresh->price);
        $this->assertSame(7, $fresh->stock_qty);
        $this->assertFalse($fresh->stock_is_estimate);
    }

    public function test_price_only_edit_keeps_estimate(): void
    {
        $shop = Shop::factory()->create();
        $product = Product::factory()->create([
            'shop_id' => $shop->id, 'price' => 45, 'stock_qty' => 8, 'stock_is_estimate' => true,
        ]);
        $this->actingAs(User::factory()->create());

        Livewire::test(StockPage::class)
            ->call('startEdit', $product->id)
            ->set('editPrice', '50')
            ->set('editQty', '8')
            ->call('saveEdit');

        $fresh = $product->fresh();
        $this->assertSame('50.00', $fresh->price);
        $this->assertTrue($fresh->stock_is_estimate);
    }

    public function test_product_image_route_serves_and_404s(): void
    {
        Storage::fake('shared');
        $shop = Shop::factory()->create();
        $product = Product::factory()->create(['shop_id' => $shop->id]);
        Storage::disk('shared')->put('scans/1/crops/a.jpg', 'fake-jpg');
        $image = ProductImage::create(['product_id' => $product->id, 'crop_path' => 'scans/1/crops/a.jpg']);
        $missing = ProductImage::create(['product_id' => $product->id, 'crop_path' => 'scans/1/crops/gone.jpg']);
        $this->actingAs(User::factory()->create());

        $response = $this->get(route('product-images.show', $image));
        $response->assertOk();
        $this->assertSame('fake-jpg', file_get_contents($response->baseResponse->getFile()->getPathname()));
        $this->get(route('product-images.show', $missing))->assertNotFound();
        $this->get(route('product-images.show', 999999))->assertNotFound();
    }

    public function test_product_image_route_requires_login(): void
    {
        $this->get('/product-images/1')->assertRedirect(route('login'));
    }
}
