<?php

use App\Enums\OrderStatus;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Shop;
use App\Services\Orders\DraftOrderBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function draftLine(string $text, string $productId, int|float $qty = 1, string $status = 'matched', array $extra = []): array
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

function draftShop(): Shop
{
    return Shop::factory()->create();
}

test('build prices matched lines from the database', function () {
    $shop = draftShop();
    $customer = Customer::factory()->create(['shop_id' => $shop->id]);
    $tang = Product::factory()->create(['shop_id' => $shop->id, 'price' => 450, 'stock_qty' => 20]);
    $salt = Product::factory()->create(['shop_id' => $shop->id, 'price' => 42, 'stock_qty' => 20]);
    $parsed = ['intent' => 'new_order', 'lines' => [
        draftLine('Tang', (string) $tang->id, 2),
        draftLine('Salt', (string) $salt->id, 1),
    ]];

    $order = (new DraftOrderBuilder)->build($shop, $customer, 'text', $parsed, null, 'tang two salt one');

    expect($order->status)->toBe(OrderStatus::Draft)
        ->and($order->customer_id)->toBe($customer->id)
        ->and($order->source_type)->toBe('text')
        ->and($order->transcript)->toBe('tang two salt one')
        ->and($order->total)->toBe('942.00')
        ->and($order->lines)->toHaveCount(2);
    $lines = $order->lines->sortBy('id')->values();
    expect($lines[0]->product_id)->toBe($tang->id)
        ->and($lines[0]->qty)->toBe(2)
        ->and($lines[0]->unit_price)->toBe('450.00')
        ->and($lines[0]->line_total)->toBe('900.00')
        ->and($lines[0]->status)->toBe('matched')
        ->and($lines[0]->note)->toBeNull()
        ->and($lines[1]->line_total)->toBe('42.00');
});

test('build merges repeated items into one line with summed qty', function () {
    $shop = draftShop();
    $tang = Product::factory()->create(['shop_id' => $shop->id, 'price' => 100, 'stock_qty' => 20]);
    $salt = Product::factory()->create(['shop_id' => $shop->id, 'price' => 10, 'stock_qty' => 20]);
    $parsed = ['lines' => [
        draftLine('Tang', (string) $tang->id, 1, 'matched', ['confidence' => 0.7]),
        draftLine('Salt', (string) $salt->id, 1),
        draftLine('tang again', (string) $tang->id, 2, 'matched', ['confidence' => 0.95]),
    ]];

    $order = (new DraftOrderBuilder)->build($shop, null, 'photo', $parsed, 'orders/list.jpg', null);

    expect($order->customer_id)->toBeNull()
        ->and($order->raw_input_path)->toBe('orders/list.jpg')
        ->and($order->lines)->toHaveCount(2);
    $lines = $order->lines->sortBy('id')->values();
    expect($lines[0]->product_id)->toBe($tang->id)
        ->and($lines[0]->qty)->toBe(3)
        ->and($lines[0]->requested_text)->toBe('Tang + tang again')
        ->and($lines[0]->match_confidence)->toBe(0.95)
        ->and($lines[0]->line_total)->toBe('300.00')
        ->and($lines[1]->product_id)->toBe($salt->id)
        ->and($order->total)->toBe('310.00');
});

test('build caps qty at stock with a note', function () {
    $shop = draftShop();
    $tang = Product::factory()->create(['shop_id' => $shop->id, 'price' => 45.5, 'stock_qty' => 3]);
    $gone = Product::factory()->create(['shop_id' => $shop->id, 'price' => 100, 'stock_qty' => 0]);
    $parsed = ['lines' => [
        draftLine('Tang x5', (string) $tang->id, 5),
        draftLine('Gone', (string) $gone->id, 2),
    ]];

    $order = (new DraftOrderBuilder)->build($shop, null, 'voice', $parsed, 'orders/note.wav', 'tang five');

    $lines = $order->lines->sortBy('id')->values();
    expect($lines[0]->qty)->toBe(3)
        ->and($lines[0]->line_total)->toBe('136.50')
        ->and($lines[0]->note)->toBe('Only 3 in stock at ৳45.5 each; wanted 5.')
        ->and($lines[1]->qty)->toBe(0)
        ->and($lines[1]->line_total)->toBe('0.00')
        ->and($lines[1]->note)->toBe('Out of stock at ৳100 each; wanted 2.')
        ->and($order->total)->toBe('136.50');
});

test('build ignores any model-supplied price', function () {
    $shop = draftShop();
    $tang = Product::factory()->create(['shop_id' => $shop->id, 'price' => 450, 'stock_qty' => 20]);
    $parsed = ['lines' => [
        draftLine('Tang', (string) $tang->id, 2, 'matched', ['unit_price' => 1, 'price' => 999]),
    ]];

    $order = (new DraftOrderBuilder)->build($shop, null, 'text', $parsed, null, null);

    expect($order->lines->first()->unit_price)->toBe('450.00')
        ->and($order->lines->first()->line_total)->toBe('900.00')
        ->and($order->total)->toBe('900.00');
});

test('build keeps ambiguous and not_found lines with alternatives', function () {
    $shop = draftShop();
    $tang = Product::factory()->create(['shop_id' => $shop->id, 'price' => 450, 'stock_qty' => 20]);
    $parsed = ['lines' => [
        draftLine('Tang', (string) $tang->id, 1),
        array_merge(draftLine('Which oil?', 'none', 1, 'ambiguous'), ['alternative_ids' => ['2', '3']]),
        draftLine('Unicorn tears', 'none', 1, 'not_found'),
    ]];

    $order = (new DraftOrderBuilder)->build($shop, null, 'text', $parsed, null, null);

    $lines = $order->lines->sortBy('id')->values();
    expect($lines)->toHaveCount(3)
        ->and($lines[1]->product_id)->toBeNull()
        ->and($lines[1]->status)->toBe('ambiguous')
        ->and($lines[1]->alternative_ids)->toBe(['2', '3'])
        ->and($lines[1]->unit_price)->toBe('0.00')
        ->and($lines[1]->line_total)->toBe('0.00')
        ->and($lines[2]->status)->toBe('not_found')
        ->and($lines[2]->product_id)->toBeNull()
        ->and($order->total)->toBe('450.00');
});

test('build treats an unresolvable match as not_found', function () {
    $shop = draftShop();
    $other = draftShop();
    $foreign = Product::factory()->create(['shop_id' => $other->id, 'price' => 10, 'stock_qty' => 9]);
    $retired = Product::factory()->create(['shop_id' => $shop->id, 'price' => 10, 'stock_qty' => 9, 'active' => false]);
    $parsed = ['lines' => [
        draftLine('Ghost', '999999'),
        draftLine('Foreign', (string) $foreign->id),
        draftLine('Retired', (string) $retired->id),
    ]];

    $order = (new DraftOrderBuilder)->build($shop, null, 'text', $parsed, null, null);

    $lines = $order->lines->sortBy('id')->values();
    expect($lines->every(fn ($line) => $line->status === 'not_found' && $line->product_id === null))->toBeTrue()
        ->and($order->total)->toBe('0.00');
});

test('build stores the parsed payload and raw responses in context', function () {
    $shop = draftShop();
    $tang = Product::factory()->create(['shop_id' => $shop->id, 'price' => 10, 'stock_qty' => 9]);
    $parsed = [
        'intent' => 'new_order',
        'lines' => [draftLine('Tang', (string) $tang->id, 1)],
        'raw' => ['id' => 'chatcmpl-1', 'choices' => []],
        'asr_raw' => ['transcript' => 'tang'],
    ];

    $order = (new DraftOrderBuilder)->build($shop, null, 'voice', $parsed, 'orders/n.wav', 'tang');

    expect($order->context)->toEqual($parsed)
        ->and($order->context['raw']['id'])->toBe('chatcmpl-1');
});

test('build keeps ৳ and Bangla text byte-identical and truncates safely', function () {
    $shop = draftShop();
    $tang = Product::factory()->create(['shop_id' => $shop->id, 'price' => 650, 'stock_qty' => 20]);
    $bangla = 'হরলিক্স চকলেট ৳৬৫০'; // Horlicks Chocolate, price in Bangla digits
    $long = str_repeat('ট', 300); // 300 chars, 900 bytes
    $parsed = ['lines' => [
        draftLine($bangla, (string) $tang->id, 1),
        draftLine($long, (string) $tang->id, 1),
    ]];

    $order = (new DraftOrderBuilder)->build($shop, null, 'text', $parsed, null, null);

    // Same product merges; both texts survive joined, cut at 255 chars.
    $stored = $order->lines->first()->requested_text;
    expect($stored)->toStartWith($bangla.' + ')
        ->and(mb_strlen($stored, 'UTF-8'))->toBe(255)
        ->and(mb_check_encoding($stored, 'UTF-8'))->toBeTrue();
    expect($bangla)->toBe('হরলিক্স চকলেট ৳৬৫০');
});
