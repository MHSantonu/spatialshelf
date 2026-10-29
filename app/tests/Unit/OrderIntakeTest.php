<?php

use App\Enums\OrderStatus;
use App\Exceptions\VoiceTranscriberException;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\Shop;
use App\Services\Catalog\MemoryMatcher;
use App\Services\Meta\MuseSpark;
use App\Services\Meta\VoiceTranscriber;
use App\Services\Orders\DraftOrderBuilder;
use App\Services\Orders\OrderIntake;
use App\Services\WorkerClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    MemoryMatcher::flush();
    $GLOBALS['intake_shared_root'] = sys_get_temp_dir().'/intake-'.uniqid();
    mkdir($GLOBALS['intake_shared_root'].'/voice', recursive: true);
    config(['spatialshelf.shared_storage_path' => $GLOBALS['intake_shared_root']]);
});

afterEach(function () {
    $root = $GLOBALS['intake_shared_root'] ?? null;
    unset($GLOBALS['intake_shared_root']);
    if (! is_string($root) || ! is_dir($root)) {
        return;
    }
    foreach (glob($root.'/voice/*') ?: [] as $file) {
        @unlink($file);
    }
    @rmdir($root.'/voice');
    @rmdir($root);
});

function intakeServices(): OrderIntake
{
    return new OrderIntake(
        new VoiceTranscriber('test-key', 'http://meta.test', worker: new WorkerClient('http://worker.test')),
        new MuseSpark('test-key', 'http://meta.test'),
        new DraftOrderBuilder,
        new WorkerClient('http://worker.test'),
    );
}

function intakeChatRaw(array $lines): array
{
    return ['choices' => [['message' => ['content' => json_encode(['intent' => 'new_order', 'lines' => $lines])]]]];
}

function intakeParsedLine(string $text, string $productId, int $qty = 1, string $status = 'matched'): array
{
    return [
        'requested_text' => $text,
        'qty' => $qty,
        'qty_unit' => '',
        'product_id' => $productId,
        'status' => $status,
        'alternative_ids' => [],
        'confidence' => 0.9,
    ];
}

test('handle takes typed text to a priced draft', function () {
    $shop = Shop::factory()->create();
    $tang = Product::factory()->create(['shop_id' => $shop->id, 'price' => 450, 'stock_qty' => 10]);
    Http::fake([
        'http://meta.test/chat/completions' => Http::response(intakeChatRaw([
            intakeParsedLine('Tang x2', (string) $tang->id, 2),
        ])),
    ]);

    $order = intakeServices()->handle($shop, null, 'try', 'Tang x2', null, null);

    expect($order->status)->toBe(OrderStatus::Simulated)
        ->and($order->source_type)->toBe('text')
        ->and($order->transcript)->toBeNull()
        ->and($order->raw_input_path)->toBeNull()
        ->and($order->total)->toBe('900.00')
        ->and($order->lines)->toHaveCount(1)
        ->and($order->context['channel'])->toBe('try')
        ->and($order->context['input_text'])->toBe('Tang x2')
        ->and($order->context['raw'])->toBeArray();
    Http::assertSent(fn (Request $request) => $request->url() === 'http://meta.test/chat/completions');
    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'worker.test'));
});

test('handle takes a photo with a caption', function () {
    $shop = Shop::factory()->create();
    $tang = Product::factory()->create(['shop_id' => $shop->id, 'price' => 100, 'stock_qty' => 10]);
    $image = tempnam(sys_get_temp_dir(), 'list');
    file_put_contents($image, 'fake-image-bytes');
    Http::fake([
        'http://meta.test/chat/completions' => Http::response(intakeChatRaw([
            intakeParsedLine('Tang', (string) $tang->id, 1),
        ])),
    ]);

    try {
        $order = intakeServices()->handle($shop, null, 'whatsapp', 'my list', $image, null);
    } finally {
        @unlink($image);
    }

    expect($order->status)->toBe(OrderStatus::Draft)
        ->and($order->source_type)->toBe('photo')
        ->and($order->raw_input_path)->toBe($image)
        ->and($order->total)->toBe('100.00')
        ->and($order->context['channel'])->toBe('whatsapp')
        ->and($order->context['input_text'])->toBe('my list')
        ->and($order->context['match_path'])->toBe('model')
        ->and($order->context['classify_raw'])->toBeArray();
    // The photo is classified first; the parse call keeps its shape.
    $recorded = Http::recorded(fn (Request $request) => $request->url() === 'http://meta.test/chat/completions'
        && ($request->data()['response_format']['json_schema']['name'] ?? null) === 'parse_order');
    $content = $recorded->values()->first()[0]->data()['messages'][1]['content'];
    expect($content[0])->toBe(['type' => 'text', 'text' => "Customer order:\nmy list"])
        ->and($content[2]['type'])->toBe('image_url');
});

test('handle transcribes audio before parsing', function () {
    $shop = Shop::factory()->create();
    $tang = Product::factory()->create(['shop_id' => $shop->id, 'price' => 50, 'stock_qty' => 10]);
    file_put_contents($GLOBALS['intake_shared_root'].'/voice/note.wav', 'fake-wav-bytes');
    Http::fake([
        'http://worker.test/audio/to-wav' => Http::response([
            'audio_path' => 'voice/note.ogg', 'wav_path' => 'voice/note.wav',
            'sample_rate' => 24000, 'channels' => 1,
        ]),
        'http://meta.test/asr/transcribe' => Http::response(['transcript' => 'Tang two piece']),
        'http://meta.test/chat/completions' => Http::response(intakeChatRaw([
            intakeParsedLine('Tang two piece', (string) $tang->id, 2),
        ])),
    ]);

    $order = intakeServices()->handle($shop, null, 'whatsapp', null, null, 'voice/note.ogg');

    expect($order->status)->toBe(OrderStatus::Draft)
        ->and($order->source_type)->toBe('voice')
        ->and($order->transcript)->toBe('Tang two piece')
        ->and($order->raw_input_path)->toBe('voice/note.ogg')
        ->and($order->context['input_text'])->toBeNull()
        ->and($order->total)->toBe('100.00');
    $recorded = Http::recorded(fn (Request $request) => $request->url() === 'http://meta.test/chat/completions');
    expect($recorded->values()->first()[0]->data()['messages'][1]['content'])
        ->toBe([['type' => 'text', 'text' => "Customer order:\nTang two piece"]]);
});

test('handle rejects an unknown channel without doing anything', function () {
    Http::fake();
    $shop = Shop::factory()->create();

    try {
        intakeServices()->handle($shop, null, 'sms', 'hi', null, null);
        $this->fail('InvalidArgumentException was not thrown.');
    } catch (InvalidArgumentException $e) {
        expect($e->getMessage())->toContain('Unknown order channel');
    }
    Http::assertNothingSent();
    expect(Order::query()->count())->toBe(0);
});

test('handle rejects empty input without doing anything', function () {
    Http::fake();
    $shop = Shop::factory()->create();

    try {
        intakeServices()->handle($shop, null, 'try', '   ', null, null);
        $this->fail('InvalidArgumentException was not thrown.');
    } catch (InvalidArgumentException $e) {
        expect($e->getMessage())->toContain('needs text');
    }
    Http::assertNothingSent();
    expect(Order::query()->count())->toBe(0);
});

test('handle aborts when transcription is empty', function () {
    $shop = Shop::factory()->create();
    Product::factory()->create(['shop_id' => $shop->id]);
    file_put_contents($GLOBALS['intake_shared_root'].'/voice/note.wav', 'fake-wav-bytes');
    Http::fake([
        'http://worker.test/audio/to-wav' => Http::response([
            'audio_path' => 'voice/note.ogg', 'wav_path' => 'voice/note.wav',
            'sample_rate' => 24000, 'channels' => 1,
        ]),
        'http://meta.test/asr/transcribe' => Http::response(['transcript' => '']),
        'http://meta.test/chat/completions' => Http::response(intakeChatRaw([])),
    ]);

    try {
        intakeServices()->handle($shop, null, 'whatsapp', null, null, 'voice/note.ogg');
        $this->fail('VoiceTranscriberException was not thrown.');
    } catch (VoiceTranscriberException $e) {
        expect($e->getMessage())->toContain('empty transcript');
    }
    Http::assertNotSent(fn (Request $request) => $request->url() === 'http://meta.test/chat/completions');
    expect(Order::query()->count())->toBe(0);
});

function intakeUnit384(float $first, float $second = 0.0): array
{
    return array_merge([$first, $second], array_fill(0, 382, 0.0));
}

function intakeClassifyRaw(string $kind): array
{
    return ['choices' => [['message' => ['content' => json_encode(['kind' => $kind])]]]];
}

function intakePhotoFile(): string
{
    $path = tempnam(sys_get_temp_dir(), 'product');
    file_put_contents($path, 'fake-image-bytes');

    return $path;
}

function intakeSingleProduct(string $kind, array $embed, callable $parse): callable
{
    return function (Request $request) use ($kind, $embed, $parse) {
        if (str_ends_with($request->url(), '/embed')) {
            return Http::response(['image_path' => 'try/photo.jpg', 'embedding' => $embed, 'dim' => 384]);
        }
        $schema = $request->data()['response_format']['json_schema']['name'] ?? null;
        if ($schema === 'classify_image') {
            return Http::response(intakeClassifyRaw($kind));
        }

        return $parse($request);
    };
}

test('a single product photo matches from memory with the caption qty', function () {
    $shop = Shop::factory()->create();
    $tang = Product::factory()->create(['shop_id' => $shop->id, 'price' => 450, 'stock_qty' => 10]);
    $far = Product::factory()->create(['shop_id' => $shop->id, 'price' => 100, 'stock_qty' => 10]);
    ProductImage::create(['product_id' => $tang->id, 'crop_path' => 'scans/1/crops/a.jpg', 'embedding' => intakeUnit384(1.0)]);
    ProductImage::create(['product_id' => $far->id, 'crop_path' => 'scans/1/crops/b.jpg', 'embedding' => intakeUnit384(-1.0)]);
    Http::fake(intakeSingleProduct('single_product', intakeUnit384(1.0),
        fn () => Http::response(intakeChatRaw([intakeParsedLine('2 ta lagbe', 'none', 2, 'not_found')]))));

    $photo = intakePhotoFile();
    try {
        $order = intakeServices()->handle($shop, null, 'whatsapp', '2 ta lagbe', $photo, null);
    } finally {
        @unlink($photo);
    }

    $line = $order->lines->first();
    expect($order->status)->toBe(OrderStatus::Draft)
        ->and($order->source_type)->toBe('photo')
        ->and($order->raw_input_path)->toBe($photo)
        ->and($order->lines)->toHaveCount(1)
        ->and($line->product_id)->toBe($tang->id)
        ->and($line->status)->toBe('matched')
        ->and($line->qty)->toBe(2)
        ->and($line->requested_text)->toBe('2 ta lagbe')
        ->and($line->match_confidence)->toBe(1.0)
        ->and($order->total)->toBe('900.00')
        ->and($order->context['match_path'])->toBe('memory')
        ->and($order->context['input_text'])->toBe('2 ta lagbe')
        ->and($order->context['classify_raw'])->toBeArray()
        ->and($order->context['qty_raw'])->toBeArray()
        ->and($order->context['memory']['distances'][0]['product_id'])->toBe($tang->id);
    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/embed')
        && ($request->data()['crop_largest_object'] ?? null) === true);
});

test('near-tied memory matches mark the photo line ambiguous', function () {
    $shop = Shop::factory()->create();
    $first = Product::factory()->create(['shop_id' => $shop->id, 'price' => 450, 'stock_qty' => 10]);
    $second = Product::factory()->create(['shop_id' => $shop->id, 'price' => 100, 'stock_qty' => 10]);
    ProductImage::create(['product_id' => $first->id, 'crop_path' => 'scans/1/crops/a.jpg', 'embedding' => intakeUnit384(1.0)]);
    // Cosine similarity 0.98 to the query: distance 0.02, inside the 0.03 tie band.
    ProductImage::create(['product_id' => $second->id, 'crop_path' => 'scans/1/crops/b.jpg', 'embedding' => intakeUnit384(0.98, sqrt(1 - 0.98 ** 2))]);
    Http::fake(intakeSingleProduct('single_product', intakeUnit384(1.0),
        fn () => $this->fail('No parse call is needed without a caption.')));

    $photo = intakePhotoFile();
    try {
        $order = intakeServices()->handle($shop, null, 'whatsapp', null, $photo, null);
    } finally {
        @unlink($photo);
    }

    $line = $order->lines->first();
    expect($order->lines)->toHaveCount(1)
        ->and($line->status)->toBe('ambiguous')
        ->and($line->product_id)->toBeNull()
        ->and($line->alternative_ids)->toBe([(string) $first->id, (string) $second->id])
        ->and($line->qty)->toBe(1)
        ->and($line->match_confidence)->toBe(1.0)
        ->and($order->total)->toBe('0.00')
        ->and($order->context['match_path'])->toBe('memory');
    Http::assertNotSent(fn (Request $request) => ($request->data()['response_format']['json_schema']['name'] ?? null) === 'parse_order');
});

test('a single product photo with no memory match falls back to the model', function () {
    $shop = Shop::factory()->create();
    $tang = Product::factory()->create(['shop_id' => $shop->id, 'price' => 450, 'stock_qty' => 10]);
    ProductImage::create(['product_id' => $tang->id, 'crop_path' => 'scans/1/crops/a.jpg', 'embedding' => intakeUnit384(-1.0)]);
    Http::fake(intakeSingleProduct('single_product', intakeUnit384(1.0),
        fn () => Http::response(intakeChatRaw([intakeParsedLine('Tang', (string) $tang->id, 1)]))));

    $photo = intakePhotoFile();
    try {
        $order = intakeServices()->handle($shop, null, 'whatsapp', 'my tang', $photo, null);
    } finally {
        @unlink($photo);
    }

    $line = $order->lines->first();
    expect($order->lines)->toHaveCount(1)
        ->and($line->product_id)->toBe($tang->id)
        ->and($line->status)->toBe('matched')
        ->and($order->context['match_path'])->toBe('model')
        ->and($order->context['input_text'])->toBe('my tang')
        ->and($order->context['classify_raw'])->toBeArray();
});

test('a classified list never touches the embed endpoint', function () {
    $shop = Shop::factory()->create();
    $tang = Product::factory()->create(['shop_id' => $shop->id, 'price' => 450, 'stock_qty' => 10]);
    Http::fake(intakeSingleProduct('list', intakeUnit384(1.0),
        fn () => Http::response(intakeChatRaw([intakeParsedLine('Tang', (string) $tang->id, 1)]))));

    $photo = intakePhotoFile();
    try {
        $order = intakeServices()->handle($shop, null, 'whatsapp', 'my list', $photo, null);
    } finally {
        @unlink($photo);
    }

    expect($order->lines)->toHaveCount(1)
        ->and($order->context['match_path'])->toBe('model');
    Http::assertNotSent(fn (Request $request) => str_ends_with($request->url(), '/embed'));
});

test('an embed failure falls back to the model', function () {
    $shop = Shop::factory()->create();
    $tang = Product::factory()->create(['shop_id' => $shop->id, 'price' => 450, 'stock_qty' => 10]);
    Http::fake(function (Request $request) use ($tang) {
        if (str_ends_with($request->url(), '/embed')) {
            throw new ConnectionException('worker down');
        }
        $schema = $request->data()['response_format']['json_schema']['name'] ?? null;
        if ($schema === 'classify_image') {
            return Http::response(intakeClassifyRaw('single_product'));
        }

        return Http::response(intakeChatRaw([intakeParsedLine('Tang', (string) $tang->id, 1)]));
    });

    $photo = intakePhotoFile();
    try {
        $order = intakeServices()->handle($shop, null, 'whatsapp', null, $photo, null);
    } finally {
        @unlink($photo);
    }

    expect($order->lines->first()->product_id)->toBe($tang->id)
        ->and($order->context['match_path'])->toBe('model')
        ->and($order->context['embed_error'])->toContain('worker down');
});

test('a failed qty parse defaults the memory line to 1', function () {
    $shop = Shop::factory()->create();
    $tang = Product::factory()->create(['shop_id' => $shop->id, 'price' => 450, 'stock_qty' => 10]);
    ProductImage::create(['product_id' => $tang->id, 'crop_path' => 'scans/1/crops/a.jpg', 'embedding' => intakeUnit384(1.0)]);
    Http::fake(function (Request $request) {
        if (str_ends_with($request->url(), '/embed')) {
            return Http::response(['image_path' => 'try/photo.jpg', 'embedding' => intakeUnit384(1.0), 'dim' => 384]);
        }
        $payload = $request->data();
        if (($payload['response_format']['json_schema']['name'] ?? null) === 'classify_image') {
            return Http::response(intakeClassifyRaw('single_product'));
        }

        return Http::response('Server error', 500);
    });

    $photo = intakePhotoFile();
    try {
        $order = intakeServices()->handle($shop, null, 'whatsapp', '2 ta lagbe', $photo, null);
    } finally {
        @unlink($photo);
    }

    expect($order->lines->first()->qty)->toBe(1)
        ->and($order->context['match_path'])->toBe('memory')
        ->and($order->context)->not->toHaveKey('qty_raw');
});
