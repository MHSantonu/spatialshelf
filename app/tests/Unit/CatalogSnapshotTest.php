<?php

use App\Models\Product;
use App\Models\Shop;
use App\Services\Orders\CatalogSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(fn () => CatalogSnapshot::flush());

test('table lists active products sorted by name in the compact format', function () {
    $shop = Shop::factory()->create();
    $oil = Product::factory()->create([
        'shop_id' => $shop->id,
        'name' => 'Rupchanda Soybean Oil',
        'size_value' => 1,
        'size_unit' => 'L',
        'price' => 380,
        'stock_qty' => 10,
        'aliases' => ['rupchanda tel', 'soybean'],
    ]);
    $chips = Product::factory()->create([
        'shop_id' => $shop->id,
        'name' => 'ACI Chips',
        'size_value' => 50,
        'size_unit' => 'g',
        'price' => 25.50,
        'stock_qty' => 0,
        'aliases' => [],
    ]);

    $table = (new CatalogSnapshot($shop->id))->table();

    expect($table)->toBe(
        "{$chips->id} | ACI Chips | 50 g | ৳25.5 | in stock: 0 | aka: \n".
        "{$oil->id} | Rupchanda Soybean Oil | 1 L | ৳380 | in stock: 10 | aka: rupchanda tel, soybean"
    );
});

test('product ids are strings in the same order as the table', function () {
    $shop = Shop::factory()->create();
    $b = Product::factory()->create(['shop_id' => $shop->id, 'name' => 'B Biscuit']);
    $a = Product::factory()->create(['shop_id' => $shop->id, 'name' => 'A Biscuit']);

    $snapshot = new CatalogSnapshot($shop->id);

    expect($snapshot->productIds())->toBe([(string) $a->id, (string) $b->id]);
});

test('inactive products and other shops are excluded', function () {
    $shop = Shop::factory()->create();
    $other = Shop::factory()->create();
    $active = Product::factory()->create(['shop_id' => $shop->id, 'name' => 'Live']);
    Product::factory()->create(['shop_id' => $shop->id, 'name' => 'Dead', 'active' => false]);
    Product::factory()->create(['shop_id' => $other->id, 'name' => 'Elsewhere']);

    $snapshot = new CatalogSnapshot($shop->id);

    expect($snapshot->productIds())->toBe([(string) $active->id])
        ->and($snapshot->table())->toContain('Live')
        ->and($snapshot->table())->not->toContain('Dead')
        ->and($snapshot->table())->not->toContain('Elsewhere');
});

test('empty catalog returns an empty table and no ids', function () {
    $shop = Shop::factory()->create();

    $snapshot = new CatalogSnapshot($shop->id);

    expect($snapshot->table())->toBe('')
        ->and($snapshot->productIds())->toBe([]);
});

test('unknown size renders as size unknown', function () {
    $shop = Shop::factory()->create();
    $product = Product::factory()->create([
        'shop_id' => $shop->id,
        'name' => 'Mystery Pack',
        'size_value' => 0,
        'size_unit' => 'unknown',
        'price' => 40,
        'stock_qty' => 7,
        'aliases' => [],
    ]);

    expect((new CatalogSnapshot($shop->id))->table())
        ->toBe("{$product->id} | Mystery Pack | size unknown | ৳40 | in stock: 7 | aka: ");
});

test('cache is busted when a product is saved or deleted', function () {
    $shop = Shop::factory()->create();
    $product = Product::factory()->create(['shop_id' => $shop->id, 'name' => 'Old Name']);
    $snapshot = new CatalogSnapshot($shop->id);
    expect($snapshot->table())->toContain('Old Name');

    $product->name = 'New Name';
    $product->save();
    expect($snapshot->table())->toContain('New Name');

    $product->delete();
    expect($snapshot->table())->toBe('')
        ->and($snapshot->productIds())->toBe([]);
});

test('bust refreshes a stale cache without touching other shops', function () {
    $shop = Shop::factory()->create();
    $other = Shop::factory()->create();
    Product::factory()->create(['shop_id' => $shop->id, 'name' => 'Cached']);
    Product::factory()->create(['shop_id' => $other->id, 'name' => 'Other']);

    $snapshot = new CatalogSnapshot($shop->id);
    $otherSnapshot = new CatalogSnapshot($other->id);
    expect($snapshot->table())->toContain('Cached');

    // Bypass model events so the observer cannot bust the cache.
    DB::table('products')->where('shop_id', $shop->id)->update(['name' => 'Changed']);
    expect($snapshot->table())->toContain('Cached');

    CatalogSnapshot::bust($shop->id);
    expect($snapshot->table())->toContain('Changed')
        ->and($otherSnapshot->table())->toContain('Other');
});
