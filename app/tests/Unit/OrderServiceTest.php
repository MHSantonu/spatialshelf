<?php

use App\Enums\OrderStatus;
use App\Exceptions\InsufficientStock;
use App\Exceptions\OrderStateException;
use App\Models\Order;
use App\Models\Product;
use App\Models\Shop;
use App\Services\Orders\DraftOrderBuilder;
use App\Services\Orders\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function orderParsedLine(string $text, string $productId, int $qty = 1, string $status = 'matched', array $extra = []): array
{
    return array_merge([
        'requested_text' => $text,
        'qty' => $qty,
        'qty_unit' => '',
        'product_id' => $productId,
        'status' => $status,
        'alternative_ids' => [],
        'confidence' => 0.9,
    ], $extra);
}

function orderDraft(Shop $shop, array $lines): Order
{
    return (new DraftOrderBuilder)->build($shop, null, 'text', ['lines' => $lines], null, null);
}

test('confirm decrements stock and learns aliases', function () {
    $shop = Shop::factory()->create();
    $tang = Product::factory()->create(['shop_id' => $shop->id, 'name' => 'Tang Orange', 'price' => 450, 'stock_qty' => 10, 'aliases' => []]);
    $salt = Product::factory()->create(['shop_id' => $shop->id, 'name' => 'ACI Pure Salt', 'price' => 42, 'stock_qty' => 5, 'aliases' => []]);
    $order = orderDraft($shop, [
        orderParsedLine('Tang', (string) $tang->id, 3),
        orderParsedLine('ACI Pure Salt', (string) $salt->id, 2),
        orderParsedLine('Which oil?', 'none', 1, 'ambiguous', ['alternative_ids' => ['9']]),
    ]);

    $confirmed = (new OrderService)->confirm($order);

    expect($confirmed->status)->toBe(OrderStatus::Confirmed)
        ->and($confirmed->confirmed_at)->not->toBeNull()
        ->and($tang->fresh()->stock_qty)->toBe(7)
        ->and($salt->fresh()->stock_qty)->toBe(3)
        ->and($tang->fresh()->aliases)->toBe(['Tang'])
        ->and($salt->fresh()->aliases)->toBe([])
        ->and($confirmed->lines->firstWhere('status', 'ambiguous')->product_id)->toBeNull();
});

test('confirm throws on short stock and changes nothing', function () {
    $shop = Shop::factory()->create();
    $tang = Product::factory()->create(['shop_id' => $shop->id, 'name' => 'Tang Orange', 'price' => 450, 'stock_qty' => 10]);
    $salt = Product::factory()->create(['shop_id' => $shop->id, 'name' => 'ACI Pure Salt', 'price' => 42, 'stock_qty' => 10]);
    $order = orderDraft($shop, [
        orderParsedLine('Tang', (string) $tang->id, 3),
        orderParsedLine('Salt', (string) $salt->id, 4),
    ]);
    // Stock moved after the draft was priced (another order, a recount).
    $tang->update(['stock_qty' => 2]);
    $salt->update(['stock_qty' => 1]);

    try {
        (new OrderService)->confirm($order);
        $this->fail('InsufficientStock was not thrown.');
    } catch (InsufficientStock $e) {
        expect($e->getMessage())->toBe(
            'Insufficient stock: Tang Orange (wanted 3, only 2 left), ACI Pure Salt (wanted 4, only 1 left).'
        )->and($e->items)->toBe([
            ['product_id' => $tang->id, 'name' => 'Tang Orange', 'wanted' => 3, 'available' => 2],
            ['product_id' => $salt->id, 'name' => 'ACI Pure Salt', 'wanted' => 4, 'available' => 1],
        ]);
    }

    expect($order->fresh()->status)->toBe(OrderStatus::Draft)
        ->and($order->fresh()->confirmed_at)->toBeNull()
        ->and($tang->fresh()->stock_qty)->toBe(2)
        ->and($salt->fresh()->stock_qty)->toBe(1)
        ->and($tang->fresh()->aliases)->toBe([]);
});

test('a second confirm cannot oversell', function () {
    $shop = Shop::factory()->create();
    $tang = Product::factory()->create(['shop_id' => $shop->id, 'price' => 450, 'stock_qty' => 10]);
    $order = orderDraft($shop, [orderParsedLine('Tang', (string) $tang->id, 3)]);
    $service = new OrderService;

    $service->confirm($order);
    expect($tang->fresh()->stock_qty)->toBe(7);

    try {
        $service->confirm($order);
        $this->fail('OrderStateException was not thrown.');
    } catch (OrderStateException $e) {
        expect($e->getMessage())->toContain('Only draft orders can be confirmed');
    }
    // Plenty of stock left, yet the retry moved nothing.
    expect($tang->fresh()->stock_qty)->toBe(7)
        ->and($order->fresh()->status)->toBe(OrderStatus::Confirmed);
});

test('confirm learns aliases case-insensitively up to 10', function () {
    $shop = Shop::factory()->create();
    $tang = Product::factory()->create([
        'shop_id' => $shop->id, 'name' => 'Tang Orange', 'price' => 10, 'stock_qty' => 9,
        'aliases' => ['tang'],
    ]);
    $full = Product::factory()->create([
        'shop_id' => $shop->id, 'name' => 'Full', 'price' => 10, 'stock_qty' => 9,
        'aliases' => array_map(fn ($i) => "alias{$i}", range(0, 9)),
    ]);
    $blank = Product::factory()->create(['shop_id' => $shop->id, 'name' => 'Blank', 'price' => 10, 'stock_qty' => 9]);
    $order = orderDraft($shop, [
        orderParsedLine('TANG', (string) $tang->id, 1),
        orderParsedLine('brand new words', (string) $full->id, 1),
        orderParsedLine('   ', (string) $blank->id, 1),
    ]);

    (new OrderService)->confirm($order);

    expect($tang->fresh()->aliases)->toBe(['tang'])
        ->and($full->fresh()->aliases)->toBe([...array_map(fn ($i) => "alias{$i}", range(1, 9)), 'brand new words'])
        ->and($blank->fresh()->aliases)->toBe([]);
});

test('confirm refuses a retired product', function () {
    $shop = Shop::factory()->create();
    $tang = Product::factory()->create(['shop_id' => $shop->id, 'name' => 'Tang Orange', 'price' => 10, 'stock_qty' => 9]);
    $order = orderDraft($shop, [orderParsedLine('Tang', (string) $tang->id, 1)]);
    $tang->update(['active' => false]);

    try {
        (new OrderService)->confirm($order);
        $this->fail('InsufficientStock was not thrown.');
    } catch (InsufficientStock $e) {
        expect($e->items)->toBe([
            ['product_id' => $tang->id, 'name' => 'Tang Orange', 'wanted' => 1, 'available' => 0],
        ]);
    }
    expect($order->fresh()->status)->toBe(OrderStatus::Draft);
});

test('cancel releases a draft without touching stock', function () {
    $shop = Shop::factory()->create();
    $tang = Product::factory()->create(['shop_id' => $shop->id, 'price' => 10, 'stock_qty' => 9]);
    $order = orderDraft($shop, [orderParsedLine('Tang', (string) $tang->id, 2)]);

    $cancelled = (new OrderService)->cancel($order);

    expect($cancelled->status)->toBe(OrderStatus::Cancelled)
        ->and($tang->fresh()->stock_qty)->toBe(9);
});

test('cancel rejects a confirmed order', function () {
    $shop = Shop::factory()->create();
    $tang = Product::factory()->create(['shop_id' => $shop->id, 'price' => 10, 'stock_qty' => 9]);
    $order = orderDraft($shop, [orderParsedLine('Tang', (string) $tang->id, 1)]);
    (new OrderService)->confirm($order);

    try {
        (new OrderService)->cancel($order);
        $this->fail('OrderStateException was not thrown.');
    } catch (OrderStateException $e) {
        expect($e->getMessage())->toContain('Only draft orders can be cancelled');
    }
    expect($order->fresh()->status)->toBe(OrderStatus::Confirmed);
});

test('confirm rejects a cancelled order', function () {
    $shop = Shop::factory()->create();
    $tang = Product::factory()->create(['shop_id' => $shop->id, 'price' => 10, 'stock_qty' => 9]);
    $order = orderDraft($shop, [orderParsedLine('Tang', (string) $tang->id, 1)]);
    (new OrderService)->cancel($order);

    try {
        (new OrderService)->confirm($order);
        $this->fail('OrderStateException was not thrown.');
    } catch (OrderStateException $e) {
        expect($e->getMessage())->toContain('Only draft orders can be confirmed');
    }
    expect($tang->fresh()->stock_qty)->toBe(9);
});

test('pack flips a confirmed order to packed', function () {
    $shop = Shop::factory()->create();
    $tang = Product::factory()->create(['shop_id' => $shop->id, 'price' => 10, 'stock_qty' => 9]);
    $order = orderDraft($shop, [orderParsedLine('Tang', (string) $tang->id, 1)]);
    (new OrderService)->confirm($order);

    $packed = (new OrderService)->pack($order);

    expect($packed->status)->toBe(OrderStatus::Packed)
        ->and($order->fresh()->status)->toBe(OrderStatus::Packed)
        ->and($tang->fresh()->stock_qty)->toBe(8);
});

test('pack rejects anything but a confirmed order', function () {
    $shop = Shop::factory()->create();
    $tang = Product::factory()->create(['shop_id' => $shop->id, 'price' => 10, 'stock_qty' => 9]);
    $order = orderDraft($shop, [orderParsedLine('Tang', (string) $tang->id, 1)]);

    try {
        (new OrderService)->pack($order);
        $this->fail('OrderStateException was not thrown.');
    } catch (OrderStateException $e) {
        expect($e->getMessage())->toContain('Only confirmed orders can be packed');
    }
    expect($order->fresh()->status)->toBe(OrderStatus::Draft);
});
