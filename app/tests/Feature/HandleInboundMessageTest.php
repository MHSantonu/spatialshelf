<?php

use App\Enums\ConversationState;
use App\Enums\OrderStatus;
use App\Jobs\HandleInboundMessage;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\Shop;
use App\Services\Orders\CatalogSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

uses(RefreshDatabase::class);

beforeEach(function () {
    CatalogSnapshot::flush();
    $GLOBALS['inb_faked'] = false;
    $GLOBALS['inb_shared_root'] = storage_path('framework/testing/inb-'.uniqid());
    mkdir($GLOBALS['inb_shared_root'], recursive: true);
    config([
        'spatialshelf.wa_access_token' => 'wa-token',
        'spatialshelf.wa_phone_number_id' => 'phone-123',
        'spatialshelf.model_api_key' => 'model-key',
        'spatialshelf.meta_base_url' => 'http://meta.test',
        'spatialshelf.worker_url' => 'http://worker.test',
        'spatialshelf.shared_storage_path' => $GLOBALS['inb_shared_root'],
    ]);
});

afterEach(function () {
    $root = $GLOBALS['inb_shared_root'] ?? null;
    unset($GLOBALS['inb_shared_root'], $GLOBALS['inb_faked'], $GLOBALS['inb_chat'], $GLOBALS['inb_overrides']);
    if (! is_string($root) || ! is_dir($root)) {
        return;
    }
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($files as $file) {
        $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
    }
    @rmdir($root);
});

function inbProduct(Shop $shop, string $name, float $price = 190, int $stock = 10): Product
{
    return Product::factory()->create([
        'shop_id' => $shop->id,
        'name' => $name,
        'size_value' => 1,
        'size_unit' => 'L',
        'price' => $price,
        'stock_qty' => $stock,
        'active' => true,
    ]);
}

function inbMatchedLine(Product $product, string $requested = 'oil', int $qty = 2): array
{
    return [
        'status' => 'matched',
        'product_id' => (string) $product->id,
        'requested_text' => $requested,
        'qty' => $qty,
        'confidence' => 0.9,
        'alternative_ids' => [],
    ];
}

function inbAmbiguousLine(string $requested, array $alternativeIds, int $qty = 1): array
{
    return [
        'status' => 'ambiguous',
        'product_id' => 'none',
        'requested_text' => $requested,
        'qty' => $qty,
        'confidence' => 0.4,
        'alternative_ids' => $alternativeIds,
    ];
}

function inbChat(array $parsed): array
{
    return ['choices' => [['message' => ['content' => json_encode($parsed)]]]];
}

function inbParsed(array $lines): array
{
    return ['intent' => 'new_order', 'lines' => $lines];
}

function inbFake(array $chat, array $overrides = []): void
{
    // Re-faking appends stub callbacks (first match wins), so register one
    // closure per test and steer it through globals on later calls.
    $GLOBALS['inb_chat'] = $chat;
    $GLOBALS['inb_overrides'] = $overrides;
    if (($GLOBALS['inb_faked'] ?? false) === true) {
        return;
    }
    $GLOBALS['inb_faked'] = true;
    Http::fake(function (Request $request) {
        $chat = $GLOBALS['inb_chat'];
        $overrides = $GLOBALS['inb_overrides'];
        $url = $request->url();
        if (str_starts_with($url, 'https://graph.facebook.com/')) {
            if (str_ends_with($url, '/messages')) {
                return Http::response(['messages' => [['id' => 'wamid.out']]]);
            }

            $meta = [
                'url' => 'https://media.test/file-1',
                'mime_type' => $overrides['media_mime'] ?? 'image/jpeg',
            ];
            if (! array_key_exists('media_size', $overrides) || $overrides['media_size'] !== null) {
                $meta['file_size'] = $overrides['media_size'] ?? 9;
            }

            return Http::response($meta);
        }
        if (str_starts_with($url, 'https://media.test/')) {
            return Http::response($overrides['media_bytes'] ?? 'fake-bytes');
        }
        if ($url === 'http://meta.test/chat/completions') {
            return Http::response($chat, $overrides['chat_status'] ?? 200);
        }
        if ($url === 'http://meta.test/asr/transcribe') {
            return Http::response(['transcript' => $overrides['transcript'] ?? 'oil two']);
        }
        if ($url === 'http://worker.test/audio/to-wav') {
            return Http::response([
                'audio_path' => 'wa-media/note.ogg', 'wav_path' => 'wa-media/note.wav',
                'sample_rate' => 24000, 'channels' => 1,
            ]);
        }

        throw new RuntimeException("Unexpected HTTP call to {$url}.");
    });
}

function inbText(string $id, string $body): array
{
    return [
        'id' => $id, 'from' => '8801700000000', 'timestamp' => '1761782400',
        'type' => 'text', 'text' => ['body' => $body],
    ];
}

function inbImage(string $id, string $mediaId, ?string $caption = null): array
{
    $image = ['mime_type' => 'image/jpeg', 'id' => $mediaId];
    if ($caption !== null) {
        $image['caption'] = $caption;
    }

    return [
        'id' => $id, 'from' => '8801700000000', 'timestamp' => '1761782400',
        'type' => 'image', 'image' => $image,
    ];
}

function inbAudio(string $id, string $mediaId): array
{
    return [
        'id' => $id, 'from' => '8801700000000', 'timestamp' => '1761782400',
        'type' => 'audio', 'audio' => ['mime_type' => 'audio/ogg', 'id' => $mediaId],
    ];
}

function inbButton(string $id, string $replyId): array
{
    return [
        'id' => $id, 'from' => '8801700000000', 'timestamp' => '1761782400',
        'type' => 'interactive',
        'interactive' => ['type' => 'button_reply', 'button_reply' => ['id' => $replyId, 'title' => 'Tap']],
    ];
}

function inbList(string $id, string $replyId): array
{
    return [
        'id' => $id, 'from' => '8801700000000', 'timestamp' => '1761782400',
        'type' => 'interactive',
        'interactive' => ['type' => 'list_reply', 'list_reply' => ['id' => $replyId, 'title' => 'Tap']],
    ];
}

function inbOther(string $id, string $type): array
{
    return [
        'id' => $id, 'from' => '8801700000000', 'timestamp' => '1761782400', 'type' => $type,
    ];
}

function inbRun(array $message, ?string $contact = 'Rahim'): void
{
    (new HandleInboundMessage($message, $contact))->handle();
}

/** @return list<array<string, mixed>> */
function inbSentMessages(): array
{
    return Http::recorded(fn (Request $request) => str_ends_with($request->url(), '/messages'))
        ->map(fn (array $pair) => $pair[0]->data())
        ->values()
        ->all();
}

/** @return list<string> */
function inbSentTexts(): array
{
    $texts = [];
    foreach (inbSentMessages() as $data) {
        if (($data['type'] ?? null) === 'text') {
            $texts[] = $data['text']['body'];
        }
    }

    return $texts;
}

/** @return list<array<string, mixed>> */
function inbSentInteractives(): array
{
    $out = [];
    foreach (inbSentMessages() as $data) {
        if (($data['type'] ?? null) === 'interactive') {
            $out[] = $data['interactive'];
        }
    }

    return $out;
}

function inbConversation(Customer $customer): Conversation
{
    return Conversation::query()->where('customer_id', $customer->id)->firstOrFail();
}

test('a text order creates the customer, marks read, acks, and sends the invoice', function () {
    $shop = Shop::factory()->create();
    $product = inbProduct($shop, 'Rupchanda Soybean Oil');
    inbFake(inbChat(inbParsed([inbMatchedLine($product)])));

    inbRun(inbText('wamid.t1', 'oil 2'));

    $customer = Customer::query()->where('wa_id', '8801700000000')->firstOrFail();
    expect($customer->name)->toBe('Rahim')
        ->and($customer->shop_id)->toBe($shop->id);
    $conversation = inbConversation($customer);
    expect($conversation->state)->toBe(ConversationState::AwaitingConfirmation);
    $order = Order::query()->findOrFail($conversation->pending_order_id);
    expect($order->status)->toBe(OrderStatus::Draft)
        ->and((float) $order->total)->toBe(380.0)
        ->and($order->lines)->toHaveCount(1);

    $sent = inbSentMessages();
    expect($sent[0])->toMatchArray(['status' => 'read', 'message_id' => 'wamid.t1']);
    expect(inbSentTexts()[0])->toBe('Got it, checking the shelf…');

    $interactives = inbSentInteractives();
    expect($interactives)->toHaveCount(1);
    $invoice = $interactives[0];
    expect($invoice['type'])->toBe('button')
        ->and($invoice['body']['text'])->toContain('Your order from')
        ->and($invoice['body']['text'])->toContain('2 × Rupchanda Soybean Oil 1L — ৳380')
        ->and($invoice['body']['text'])->toContain('Total: ৳380')
        ->and($invoice['action']['buttons'])->toBe([
            ['type' => 'reply', 'reply' => ['id' => "confirm:{$order->id}", 'title' => 'Confirm']],
            ['type' => 'reply', 'reply' => ['id' => "change:{$order->id}", 'title' => 'Change']],
            ['type' => 'reply', 'reply' => ['id' => "cancel:{$order->id}", 'title' => 'Cancel']],
        ]);
});

test('a new order while awaiting confirmation cancels the previous draft', function () {
    $shop = Shop::factory()->create();
    $old = inbProduct($shop, 'Old Oil');
    $new = inbProduct($shop, 'New Oil');
    inbFake(inbChat(inbParsed([inbMatchedLine($old)])));
    inbRun(inbText('wamid.old', 'old oil'));
    $customer = Customer::query()->where('wa_id', '8801700000000')->firstOrFail();
    $oldOrder = Order::query()->findOrFail(inbConversation($customer)->pending_order_id);

    inbFake(inbChat(inbParsed([inbMatchedLine($new)])));
    inbRun(inbText('wamid.new', 'new oil'));

    expect($oldOrder->refresh()->status)->toBe(OrderStatus::Cancelled);
    $pending = Order::query()->findOrFail(inbConversation($customer)->refresh()->pending_order_id);
    expect($pending->id)->not->toBe($oldOrder->id)
        ->and($pending->status)->toBe(OrderStatus::Draft);
    $lists = inbSentInteractives();
    expect($lists)->toHaveCount(2)
        ->and($lists[0]['body']['text'])->toContain('Old Oil')
        ->and($lists[1]['body']['text'])->toContain('New Oil');
});

test('an ambiguous line asks a clarification list', function () {
    $shop = Shop::factory()->create();
    $first = inbProduct($shop, 'Rupchanda Soybean Oil', 190);
    $second = inbProduct($shop, 'Fresh Soybean Oil', 185);
    inbFake(inbChat(inbParsed([
        inbAmbiguousLine('oil', [(string) $first->id, (string) $second->id]),
    ])));

    inbRun(inbText('wamid.amb', 'oil'));

    $customer = Customer::query()->where('wa_id', '8801700000000')->firstOrFail();
    $conversation = inbConversation($customer);
    expect($conversation->state)->toBe(ConversationState::AwaitingClarification);
    $order = Order::query()->findOrFail($conversation->pending_order_id);
    $line = $order->lines()->firstOrFail();

    $interactives = inbSentInteractives();
    expect($interactives)->toHaveCount(1);
    $list = $interactives[0];
    expect($list['type'])->toBe('list')
        ->and($list['body']['text'])->toBe('Which oil do you want?')
        ->and($list['action']['button'])->toBe('Choose');
    $rows = $list['action']['sections'][0]['rows'];
    expect($rows)->toHaveCount(3)
        ->and($rows[0]['id'])->toBe("pick:{$line->id}:{$first->id}")
        ->and($rows[0]['title'])->toBe('Rupchanda Soybean Oil')
        ->and($rows[0]['description'])->toContain('৳190')
        ->and($rows[1]['id'])->toBe("pick:{$line->id}:{$second->id}")
        ->and($rows[2]['id'])->toBe("skip:{$line->id}");
});

test('picking an option prices the line from the DB and invoices', function () {
    $shop = Shop::factory()->create();
    $first = inbProduct($shop, 'Rupchanda Soybean Oil', 190, 5);
    $second = inbProduct($shop, 'Fresh Soybean Oil', 185, 5);
    inbFake(inbChat(inbParsed([
        inbAmbiguousLine('oil', [(string) $first->id, (string) $second->id], 2),
    ])));
    inbRun(inbText('wamid.amb', 'oil 2'));
    $customer = Customer::query()->where('wa_id', '8801700000000')->firstOrFail();
    $order = Order::query()->findOrFail(inbConversation($customer)->pending_order_id);
    $line = $order->lines()->firstOrFail();

    inbRun(inbList('wamid.pick', "pick:{$line->id}:{$second->id}"), 'Rahim');

    $line->refresh();
    expect($line->status)->toBe('matched')
        ->and((int) $line->product_id)->toBe($second->id)
        ->and((float) $line->unit_price)->toBe(185.0)
        ->and((float) $line->line_total)->toBe(370.0)
        ->and($order->refresh()->total)->toBe('370.00')
        ->and(inbConversation($customer)->refresh()->state)->toBe(ConversationState::AwaitingConfirmation);
    $invoice = inbSentInteractives()[1];
    expect($invoice['type'])->toBe('button')
        ->and($invoice['body']['text'])->toContain('2 × Fresh Soybean Oil 1L — ৳370');
});

test('picking moves to the next ambiguous line while any remain', function () {
    $shop = Shop::factory()->create();
    $oil = inbProduct($shop, 'Rupchanda Soybean Oil');
    $saltA = inbProduct($shop, 'ACI Pure Salt');
    $saltB = inbProduct($shop, 'Fresh Salt');
    inbFake(inbChat(inbParsed([
        inbAmbiguousLine('oil', [(string) $oil->id]),
        inbAmbiguousLine('salt', [(string) $saltA->id, (string) $saltB->id]),
    ])));
    inbRun(inbText('wamid.amb', 'oil and salt'));
    $customer = Customer::query()->where('wa_id', '8801700000000')->firstOrFail();
    $order = Order::query()->findOrFail(inbConversation($customer)->pending_order_id);
    $firstLine = $order->lines()->orderBy('id')->firstOrFail();

    inbRun(inbList('wamid.pick', "pick:{$firstLine->id}:{$oil->id}"), 'Rahim');

    expect(inbConversation($customer)->refresh()->state)->toBe(ConversationState::AwaitingClarification);
    $lists = inbSentInteractives();
    expect($lists)->toHaveCount(2)
        ->and($lists[1]['body']['text'])->toBe('Which salt do you want?');
});

test('skipping drops the line and invoices the rest', function () {
    $shop = Shop::factory()->create();
    $oil = inbProduct($shop, 'Rupchanda Soybean Oil');
    $salt = inbProduct($shop, 'ACI Pure Salt', 65);
    inbFake(inbChat(inbParsed([
        inbMatchedLine($oil),
        inbAmbiguousLine('salt', [(string) $salt->id]),
    ])));
    inbRun(inbText('wamid.amb', 'oil and salt'));
    $customer = Customer::query()->where('wa_id', '8801700000000')->firstOrFail();
    $order = Order::query()->findOrFail(inbConversation($customer)->pending_order_id);
    $line = $order->lines()->where('status', 'ambiguous')->firstOrFail();

    inbRun(inbList('wamid.skip', "skip:{$line->id}"), 'Rahim');

    expect($line->refresh()->status)->toBe('not_found')
        ->and($line->product_id)->toBeNull()
        ->and(inbConversation($customer)->refresh()->state)->toBe(ConversationState::AwaitingConfirmation);
    $invoice = inbSentInteractives()[1];
    expect($invoice['body']['text'])->toContain('2 × Rupchanda Soybean Oil 1L — ৳380')
        ->and($invoice['body']['text'])->toContain('Not available: salt');
});

test('confirm captures stock and idles the conversation', function () {
    $shop = Shop::factory()->create();
    $product = inbProduct($shop, 'Rupchanda Soybean Oil', 190, 10);
    inbFake(inbChat(inbParsed([inbMatchedLine($product)])));
    inbRun(inbText('wamid.t1', 'oil 2'));
    $customer = Customer::query()->where('wa_id', '8801700000000')->firstOrFail();
    $order = Order::query()->findOrFail(inbConversation($customer)->pending_order_id);

    inbRun(inbButton('wamid.confirm', "confirm:{$order->id}"), 'Rahim');

    expect($order->refresh()->status)->toBe(OrderStatus::Confirmed)
        ->and($product->refresh()->stock_qty)->toBe(8);
    $conversation = inbConversation($customer)->refresh();
    expect($conversation->state)->toBe(ConversationState::Idle)
        ->and($conversation->pending_order_id)->toBeNull();
    expect(inbSentTexts())->toContain("Order #{$order->id} confirmed. We'll message you when it's packed.");
});

test('confirming twice only confirms once', function () {
    $shop = Shop::factory()->create();
    $product = inbProduct($shop, 'Rupchanda Soybean Oil', 190, 10);
    inbFake(inbChat(inbParsed([inbMatchedLine($product)])));
    inbRun(inbText('wamid.t1', 'oil 2'));
    $customer = Customer::query()->where('wa_id', '8801700000000')->firstOrFail();
    $order = Order::query()->findOrFail(inbConversation($customer)->pending_order_id);

    inbRun(inbButton('wamid.c1', "confirm:{$order->id}"), 'Rahim');
    inbRun(inbButton('wamid.c2', "confirm:{$order->id}"), 'Rahim');

    expect($product->refresh()->stock_qty)->toBe(8);
    expect(inbSentTexts())->toContain('That order is no longer active. Send your list again to start a new one.');
});

test('short stock sends what is short and an adjusted invoice', function () {
    $shop = Shop::factory()->create();
    $product = inbProduct($shop, 'Rupchanda Soybean Oil', 190, 5);
    inbFake(inbChat(inbParsed([inbMatchedLine($product)])));
    inbRun(inbText('wamid.t1', 'oil 2'));
    $customer = Customer::query()->where('wa_id', '8801700000000')->firstOrFail();
    $order = Order::query()->findOrFail(inbConversation($customer)->pending_order_id);
    $product->update(['stock_qty' => 1]);

    inbRun(inbButton('wamid.confirm', "confirm:{$order->id}"), 'Rahim');

    $texts = inbSentTexts();
    expect($texts)->toContain("Short on stock:\n• Rupchanda Soybean Oil: wanted 2, only 1 left");
    $line = $order->lines()->firstOrFail()->refresh();
    expect((int) $line->qty)->toBe(1)
        ->and((float) $line->line_total)->toBe(190.0)
        ->and($line->note)->toContain('Only 1 in stock');
    $invoice = inbSentInteractives()[1];
    expect($invoice['body']['text'])->toContain('1 × Rupchanda Soybean Oil 1L — ৳190')
        ->and($invoice['body']['text'])->toContain('Total: ৳190');
    expect(inbConversation($customer)->refresh()->state)->toBe(ConversationState::AwaitingConfirmation);

    inbRun(inbButton('wamid.confirm2', "confirm:{$order->id}"), 'Rahim');

    expect($order->refresh()->status)->toBe(OrderStatus::Confirmed)
        ->and($product->refresh()->stock_qty)->toBe(0);
});

test('cancel cancels the draft and idles', function () {
    $shop = Shop::factory()->create();
    $product = inbProduct($shop, 'Rupchanda Soybean Oil', 190, 10);
    inbFake(inbChat(inbParsed([inbMatchedLine($product)])));
    inbRun(inbText('wamid.t1', 'oil 2'));
    $customer = Customer::query()->where('wa_id', '8801700000000')->firstOrFail();
    $order = Order::query()->findOrFail(inbConversation($customer)->pending_order_id);

    inbRun(inbButton('wamid.cancel', "cancel:{$order->id}"), 'Rahim');

    expect($order->refresh()->status)->toBe(OrderStatus::Cancelled)
        ->and($product->refresh()->stock_qty)->toBe(10);
    $conversation = inbConversation($customer)->refresh();
    expect($conversation->state)->toBe(ConversationState::Idle)
        ->and($conversation->pending_order_id)->toBeNull();
    expect(inbSentTexts())->toContain('Order cancelled.');
});

test('change asks for the full list again and keeps the draft', function () {
    $shop = Shop::factory()->create();
    $product = inbProduct($shop, 'Rupchanda Soybean Oil');
    inbFake(inbChat(inbParsed([inbMatchedLine($product)])));
    inbRun(inbText('wamid.t1', 'oil 2'));
    $customer = Customer::query()->where('wa_id', '8801700000000')->firstOrFail();
    $order = Order::query()->findOrFail(inbConversation($customer)->pending_order_id);

    inbRun(inbButton('wamid.change', "change:{$order->id}"), 'Rahim');

    expect(inbSentTexts())->toContain('Send the full list again, with your changes.');
    expect($order->refresh()->status)->toBe(OrderStatus::Draft)
        ->and(inbConversation($customer)->refresh()->pending_order_id)->toBe($order->id);
});

test('stickers and locations get the fallback text', function (string $type) {
    Shop::factory()->create();
    inbFake(inbChat(inbParsed([])));

    inbRun(inbOther('wamid.x', $type));

    expect(inbSentTexts())->toContain('Send me your list as text, a photo, or a voice note.');
})->with(['sticker' => ['sticker'], 'location' => ['location']]);

test('a photo downloads the media and orders from it', function () {
    $shop = Shop::factory()->create();
    $product = inbProduct($shop, 'Rupchanda Soybean Oil');
    inbFake(inbChat(inbParsed([inbMatchedLine($product)])), ['media_mime' => 'image/jpeg']);

    inbRun(inbImage('wamid.img', 'media-9', 'oil 2'));

    $order = Order::query()->firstOrFail();
    expect($order->source_type)->toBe('photo')
        ->and($order->raw_input_path)->toBe('wa-media/media-9.jpg')
        ->and(is_file($GLOBALS['inb_shared_root'].'/wa-media/media-9.jpg'))->toBeTrue();
    expect(inbSentInteractives()[0]['body']['text'])->toContain('2 × Rupchanda Soybean Oil 1L — ৳380');
});

test('a voice note downloads, transcribes, and orders from the transcript', function () {
    $shop = Shop::factory()->create();
    $product = inbProduct($shop, 'Tang Orange', 120);
    mkdir($GLOBALS['inb_shared_root'].'/wa-media', recursive: true);
    file_put_contents($GLOBALS['inb_shared_root'].'/wa-media/note.wav', 'fake-wav');
    inbFake(inbChat(inbParsed([inbMatchedLine($product, 'tang', 2)])), [
        'media_mime' => 'audio/ogg',
        'transcript' => 'tang two',
    ]);

    inbRun(inbAudio('wamid.aud', 'media-7'));

    $order = Order::query()->firstOrFail();
    expect($order->source_type)->toBe('voice')
        ->and($order->transcript)->toBe('tang two')
        ->and($order->raw_input_path)->toBe('wa-media/media-7.ogg');
    expect(inbSentInteractives()[0]['body']['text'])->toContain('2 × Tang Orange 1L — ৳240');
});

test('a failure sends the sorry text and logs the message id', function () {
    Log::spy();
    Shop::factory()->create();
    inbFake(['error' => ['message' => 'overloaded', 'code' => 500]], ['chat_status' => 500]);

    inbRun(inbText('wamid.fail', 'oil 2'));

    expect(inbSentTexts())->toContain('Sorry, something went wrong. Please try again.');
    Log::shouldHaveReceived('error')->once()->withArgs(
        fn (string $message, array $context) => $message === 'HandleInboundMessage failed.'
            && ($context['message_id'] ?? null) === 'wamid.fail'
    );
});

test('oversized media is rejected with a friendly message', function (string $kind) {
    Shop::factory()->create();
    inbFake(inbChat(inbParsed([])), [
        'media_mime' => $kind === 'image' ? 'image/jpeg' : 'audio/ogg',
        'media_size' => 20 * 1024 * 1024,
    ]);
    $message = $kind === 'image' ? inbImage('wamid.big', 'media-big') : inbAudio('wamid.big', 'media-big');

    inbRun($message);

    expect(inbSentTexts())->toContain('That file is over the 16 MB limit. Please send a smaller photo or a shorter voice note.');
    expect(Order::query()->count())->toBe(0);
    expect(inbSentInteractives())->toBeEmpty();
})->with(['image' => ['image'], 'audio' => ['audio']]);

test('oversized media is rejected by body size when metadata lacks one', function () {
    Shop::factory()->create();
    inbFake(inbChat(inbParsed([])), [
        'media_size' => null,
        'media_bytes' => str_repeat('x', 17 * 1024 * 1024),
    ]);

    inbRun(inbImage('wamid.big', 'media-big'));

    expect(inbSentTexts())->toContain('That file is over the 16 MB limit. Please send a smaller photo or a shorter voice note.');
    expect(Order::query()->count())->toBe(0);
});

test('a pick for an unknown line gets the fallback text', function () {
    $shop = Shop::factory()->create();
    $product = inbProduct($shop, 'Rupchanda Soybean Oil');
    inbFake(inbChat(inbParsed([inbMatchedLine($product)])));
    inbRun(inbText('wamid.t1', 'oil 2'));

    inbRun(inbList('wamid.pick', 'pick:999:1'), 'Rahim');

    expect(inbSentTexts())->toContain('Send me your list as text, a photo, or a voice note.');
});
