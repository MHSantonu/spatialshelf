<?php

use App\Enums\OrderStatus;
use App\Exceptions\VoiceTranscriberException;
use App\Models\Order;
use App\Models\Product;
use App\Models\Shop;
use App\Services\Meta\MuseSpark;
use App\Services\Meta\VoiceTranscriber;
use App\Services\Orders\DraftOrderBuilder;
use App\Services\Orders\OrderIntake;
use App\Services\WorkerClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
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
        ->and($order->context['channel'])->toBe('whatsapp');
    $recorded = Http::recorded(fn (Request $request) => $request->url() === 'http://meta.test/chat/completions');
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
