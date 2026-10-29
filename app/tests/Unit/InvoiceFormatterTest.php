<?php

use App\Models\Order;
use App\Models\Product;
use App\Models\Shop;
use App\Services\Orders\DraftOrderBuilder;
use App\Services\WhatsApp\InvoiceFormatter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function invProduct(Shop $shop, string $name, float $price, int $stock, float $size = 1, string $unit = 'L'): Product
{
    return Product::factory()->create([
        'shop_id' => $shop->id,
        'name' => $name,
        'size_value' => $size,
        'size_unit' => $unit,
        'price' => $price,
        'stock_qty' => $stock,
        'active' => true,
    ]);
}

function invLine(string $text, string $productId, int $qty = 1, string $status = 'matched'): array
{
    return [
        'status' => $status,
        'product_id' => $productId,
        'requested_text' => $text,
        'qty' => $qty,
        'confidence' => 0.9,
        'alternative_ids' => [],
    ];
}

function invDraft(Shop $shop, array $lines): Order
{
    return (new DraftOrderBuilder)->build($shop, null, 'text', ['lines' => $lines], null, null);
}

test('the invoice matches the committed snapshot', function () {
    $shop = Shop::factory()->create(['name' => 'Rahman Store']);
    $lux = invProduct($shop, 'Lux Soap', 45, 10, 100, 'g');
    $oil = invProduct($shop, 'Rupchanda Soybean Oil', 190, 1);
    $order = invDraft($shop, [
        invLine('lux', (string) $lux->id, 2),
        invLine('oil', (string) $oil->id, 2),
        invLine('Moshur dal', 'none', 1, 'not_found'),
    ]);

    $message = (new InvoiceFormatter)->format($order);

    $snapshot = file_get_contents(dirname(__DIR__).'/Fixtures/invoice-snapshot.txt');
    expect($message['body'])->toBe($snapshot)
        ->and($message['buttons'])->toBe([
            ["confirm:{$order->id}", 'Confirm'],
            ["change:{$order->id}", 'Change'],
            ["cancel:{$order->id}", 'Cancel'],
        ]);
});

test('long invoices truncate lines with a more-items count', function () {
    $shop = Shop::factory()->create(['name' => 'Rahman Store']);
    $lines = [];
    foreach (range(1, 40) as $i) {
        $product = invProduct($shop, "Shelf Product Number {$i} With A Long Name", 10, 99);
        $lines[] = invLine("item {$i}", (string) $product->id);
    }
    $order = invDraft($shop, $lines);

    $body = (new InvoiceFormatter)->format($order)['body'];

    expect(mb_strlen($body, 'UTF-8'))->toBeLessThanOrEqual(1024)
        ->and($body)->toContain('Your order from Rahman Store')
        ->and($body)->toContain('Total: ৳400')
        ->and($body)->toMatch('/^\+\d+ more items$/m');
});

test('an order with nothing priced still renders its sections', function () {
    $shop = Shop::factory()->create(['name' => 'Rahman Store']);
    $order = invDraft($shop, [invLine('Moshur dal', 'none', 1, 'not_found')]);

    $body = (new InvoiceFormatter)->format($order)['body'];

    expect($body)->toBe(implode("\n", [
        'Your order from Rahman Store',
        '--------------------',
        'Total: ৳0',
        'Not available: Moshur dal',
    ]));
});
