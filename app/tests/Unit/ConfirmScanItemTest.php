<?php

use App\Actions\ConfirmScanItem;
use App\Enums\ScanItemDecision;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\Scan;
use App\Models\ScanItem;
use App\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function confirmItem(Scan $scan, array $overrides = []): ScanItem
{
    return ScanItem::create(array_merge([
        'scan_id' => $scan->id,
        'crop_path' => "scans/{$scan->id}/crops/kf0_0.jpg",
        'embedding' => [1, 0, 0],
        'facings' => 2,
        'est_qty' => 8,
        'shelf_row' => 3,
        'shelf_order' => 4,
        'extracted' => [
            'name' => 'Tang Orange',
            'brand' => 'Tang',
            'size_value' => 0,
            'size_unit' => 'unknown',
            'category' => 'drinks',
            'mrp_text' => '',
            'confidence' => 0.9,
        ],
        'decision' => ScanItemDecision::Pending,
    ], $overrides));
}

test('matched path updates price stock and shelf and adds an image', function () {
    $shop = Shop::factory()->create();
    $product = Product::factory()->create(['shop_id' => $shop->id, 'price' => 40]);
    $scan = Scan::create(['shop_id' => $shop->id, 'video_path' => 'uploads/a.mp4']);
    $item = confirmItem($scan, ['matched_product_id' => $product->id]);

    $result = (new ConfirmScanItem)->execute($item, 45.0);

    expect($result->id)->toBe($product->id)
        ->and($product->fresh()->price)->toBe('45.00')
        ->and($product->fresh()->stock_qty)->toBe(8)
        ->and($product->fresh()->stock_is_estimate)->toBeTrue()
        ->and($product->fresh()->shelf_row)->toBe(3)
        ->and($product->fresh()->shelf_order)->toBe(4)
        ->and($item->fresh()->decision)->toBe(ScanItemDecision::Confirmed);
    $image = ProductImage::query()->firstOrFail();
    expect($image->product_id)->toBe($product->id)
        ->and($image->crop_path)->toBe($item->crop_path)
        ->and($image->embedding)->toBe([1, 0, 0])
        ->and($image->source_scan_id)->toBe($scan->id);
});

test('matched path leaves an unchanged price alone', function () {
    $shop = Shop::factory()->create();
    $product = Product::factory()->create(['shop_id' => $shop->id, 'price' => 45]);
    $scan = Scan::create(['shop_id' => $shop->id, 'video_path' => 'uploads/a.mp4']);
    $item = confirmItem($scan, ['matched_product_id' => $product->id]);

    (new ConfirmScanItem)->execute($item, 45.0, ['qty' => 10]);

    expect($product->fresh()->price)->toBe('45.00')
        ->and($product->fresh()->stock_qty)->toBe(10)
        ->and($product->fresh()->stock_is_estimate)->toBeFalse();
});

test('unmatched path creates a product from the edited fields', function () {
    $shop = Shop::factory()->create();
    $scan = Scan::create(['shop_id' => $shop->id, 'video_path' => 'uploads/a.mp4']);
    $item = confirmItem($scan);

    $product = (new ConfirmScanItem)->execute($item, 55.0, [
        'name' => 'Sajeeb Mango Juice',
        'size_value' => 250,
        'size_unit' => 'ml',
        'category' => 'drinks',
        'qty' => 9,
    ]);

    expect($product->name)->toBe('Sajeeb Mango Juice')
        ->and($product->price)->toBe('55.00')
        ->and($product->stock_qty)->toBe(9)
        ->and($product->stock_is_estimate)->toBeFalse()
        ->and($product->shelf_row)->toBe(3);
    $fresh = $item->fresh();
    expect($fresh->decision)->toBe(ScanItemDecision::Confirmed)
        ->and($fresh->matched_product_id)->toBe($product->id)
        ->and($fresh->extracted['name'])->toBe('Sajeeb Mango Juice')
        ->and($fresh->est_qty)->toBe(9)
        ->and(ProductImage::query()->where('product_id', $product->id)->count())->toBe(1);
});

test('unmatched path falls back to the item values without edits', function () {
    $shop = Shop::factory()->create();
    $scan = Scan::create(['shop_id' => $shop->id, 'video_path' => 'uploads/a.mp4']);
    $item = confirmItem($scan);

    $product = (new ConfirmScanItem)->execute($item, 45.0);

    expect($product->name)->toBe('Tang Orange')
        ->and($product->stock_qty)->toBe(8)
        ->and($product->stock_is_estimate)->toBeTrue();
});
