<?php

use App\Exceptions\MuseSparkException;
use App\Models\Product;
use App\Models\Shop;
use App\Services\Meta\MuseSpark;
use App\Services\Orders\CatalogSnapshot;
use App\Services\Prompts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(fn () => CatalogSnapshot::flush());

function parseOrderFixture(string $name): array
{
    $path = dirname(__DIR__, 3)."/spikes/out/orders/{$name}.json";

    return json_decode(file_get_contents($path), true);
}

function parseOrderJpeg(string $path, int $width, int $height, ?int $orientation = null): void
{
    $image = imagecreatetruecolor($width, $height);
    imagejpeg($image, $path, 92);
    imagedestroy($image);

    if ($orientation === null) {
        return;
    }

    // Minimal EXIF APP1 segment carrying only the Orientation tag, spliced
    // after SOI so exif_read_data picks it up like a phone photo's header.
    $tiff = "II*\x00\x08\x00\x00\x00"
        ."\x01\x00"
        ."\x12\x01\x03\x00\x01\x00\x00\x00".pack('v', $orientation)."\x00\x00"
        ."\x00\x00\x00\x00";
    $app1 = "\xFF\xE1".pack('n', strlen($tiff) + 8).'Exif'."\x00\x00".$tiff;
    $jpeg = file_get_contents($path);
    file_put_contents($path, "\xFF\xD8".$app1.substr($jpeg, 2));
}

function parseOrderShop(): Shop
{
    $shop = Shop::factory()->create();
    Product::factory()->create(['shop_id' => $shop->id, 'name' => 'Tang Orange']);
    Product::factory()->create(['shop_id' => $shop->id, 'name' => 'ACI Pure Salt']);

    return $shop;
}

function gdAvailable(): bool
{
    return function_exists('imagecreatetruecolor') && function_exists('imagecreatefromstring');
}

test('parseOrder puts the catalog table first and swaps the id enum', function () {
    $shop = parseOrderShop();
    $snapshot = new CatalogSnapshot($shop->id);
    $chatRaw = parseOrderFixture('list_1')['chat_raw'];

    Http::fake(function (Request $request) use ($snapshot, $chatRaw) {
        $payload = $request->data();
        $system = $payload['messages'][0]['content'];
        $table = $snapshot->table();

        expect($payload['model'])->toBe('muse-spark-1.3')
            ->and($payload['messages'][0]['role'])->toBe('system')
            ->and($system)->toBe($table."\n\n".Prompts::text('parse_order'))
            ->and(str_starts_with($system, $table))->toBeTrue()
            ->and($payload['messages'][1])->toBe([
                'role' => 'user',
                'content' => [['type' => 'text', 'text' => "Customer order:\n2 Tang Orange"]],
            ])
            ->and($payload['response_format']['json_schema']['name'])->toBe('parse_order');

        $schema = $payload['response_format']['json_schema']['schema'];
        $expected = Prompts::schema('parse_order');
        $expected['properties']['lines']['items']['properties']['product_id']['enum'] =
            [...$snapshot->productIds(), 'none'];
        expect($schema)->toBe($expected);

        return Http::response($chatRaw);
    });

    $result = (new MuseSpark('test-key', 'http://meta.test'))->parseOrder($shop, '2 Tang Orange', null);

    expect($result['parsed'])->toEqual(parseOrderFixture('list_1')['parsed'])
        ->and($result['raw'])->toEqual($chatRaw);
});

test('parseOrder decodes every saved spike response', function (string $file, string $kind) {
    $shop = parseOrderShop();
    $fixture = parseOrderFixture($file);

    Http::fake(fn () => Http::response($fixture['chat_raw']));

    $spark = new MuseSpark('test-key', 'http://meta.test');
    if ($kind === 'image') {
        if (! gdAvailable()) {
            $this->markTestSkipped('GD is not installed.');
        }
        $path = tempnam(sys_get_temp_dir(), 'order').'.jpg';
        parseOrderJpeg($path, 8, 6);
        try {
            $result = $spark->parseOrder($shop, null, $path);
        } finally {
            @unlink($path);
        }
    } else {
        $result = $spark->parseOrder($shop, $fixture['transcript'], null);
    }

    expect($result['parsed'])->toEqual($fixture['parsed'])
        ->and($result['parsed']['intent'])->toBe('new_order')
        ->and($result['raw'])->toEqual($fixture['chat_raw']);

    Http::assertSent(function (Request $request) use ($kind, $fixture) {
        $content = $request->data()['messages'][1]['content'];
        if ($kind === 'image') {
            return $content[0]['text'] === "Read the customer's handwritten order in this photo and match each item to the catalog."
                && str_starts_with($content[1]['image_url']['url'], 'data:image/jpeg;base64,');
        }

        return $content === [['type' => 'text', 'text' => "Customer order:\n{$fixture['transcript']}"]];
    });
})->with([
    'list photo' => ['list_1', 'image'],
    'rotated list photo' => ['list_2_rotated', 'image'],
    'voice transcript' => ['voice_1', 'text'],
    'voice transcript with repeats' => ['voice_2', 'text'],
]);

test('parseOrder leaves the versioned schema template untouched', function () {
    $shop = parseOrderShop();
    Http::fake(fn () => Http::response(parseOrderFixture('voice_1')['chat_raw']));

    (new MuseSpark('test-key', 'http://meta.test'))->parseOrder($shop, 'hello', null);

    expect(Prompts::schema('parse_order')['properties']['lines']['items']['properties']['product_id']['enum'])
        ->toBe(['<live ids>', 'none']);
});

test('parseOrder sends text and image together', function () {
    if (! gdAvailable()) {
        $this->markTestSkipped('GD is not installed.');
    }
    $shop = parseOrderShop();
    Http::fake(function (Request $request) {
        $content = $request->data()['messages'][1]['content'];
        expect($content)->toHaveCount(3)
            ->and($content[0])->toBe(['type' => 'text', 'text' => "Customer order:\nand this photo"])
            ->and($content[2]['type'])->toBe('image_url');

        return Http::response(parseOrderFixture('list_1')['chat_raw']);
    });

    $path = tempnam(sys_get_temp_dir(), 'order').'.jpg';
    parseOrderJpeg($path, 8, 6);
    try {
        (new MuseSpark('test-key', 'http://meta.test'))->parseOrder($shop, 'and this photo', $path);
    } finally {
        @unlink($path);
    }
});

test('parseOrder rotates a photo by its EXIF orientation', function () {
    if (! gdAvailable()) {
        $this->markTestSkipped('GD is not installed.');
    }
    if (! function_exists('exif_read_data')) {
        $this->markTestSkipped('exif is not installed.');
    }
    $shop = parseOrderShop();
    $path = tempnam(sys_get_temp_dir(), 'order').'.jpg';
    parseOrderJpeg($path, 8, 6, 6); // phone portrait: stored sideways, shown upright
    expect(exif_read_data($path)['Orientation'] ?? null)->toBe(6);

    Http::fake(function (Request $request) {
        $url = $request->data()['messages'][1]['content'][1]['image_url']['url'];
        $image = imagecreatefromstring(base64_decode(substr($url, strlen('data:image/jpeg;base64,'))));
        expect(imagesx($image))->toBe(6)->and(imagesy($image))->toBe(8);
        imagedestroy($image);

        return Http::response(parseOrderFixture('list_2_rotated')['chat_raw']);
    });

    try {
        $result = (new MuseSpark('test-key', 'http://meta.test'))->parseOrder($shop, null, $path);
    } finally {
        @unlink($path);
    }

    expect($result['parsed']['lines'])->toHaveCount(4);
});

test('parseOrder downscales a large photo to 1600px before sending', function () {
    if (! gdAvailable()) {
        $this->markTestSkipped('GD is not installed.');
    }
    $shop = parseOrderShop();
    $path = tempnam(sys_get_temp_dir(), 'order').'.jpg';
    parseOrderJpeg($path, 3200, 2400);

    Http::fake(function (Request $request) {
        $url = $request->data()['messages'][1]['content'][1]['image_url']['url'];
        $image = imagecreatefromstring(base64_decode(substr($url, strlen('data:image/jpeg;base64,'))));
        expect(imagesx($image))->toBe(1600)->and(imagesy($image))->toBe(1200);
        imagedestroy($image);

        return Http::response(parseOrderFixture('list_1')['chat_raw']);
    });

    try {
        (new MuseSpark('test-key', 'http://meta.test'))->parseOrder($shop, null, $path);
    } finally {
        @unlink($path);
    }
});

test('parseOrder sends undecodable bytes as-is', function () {
    $shop = parseOrderShop();
    $path = tempnam(sys_get_temp_dir(), 'order');
    file_put_contents($path, 'not-an-image');

    Http::fake(function (Request $request) {
        expect($request->data()['messages'][1]['content'][1]['image_url']['url'])
            ->toBe('data:image/jpeg;base64,'.base64_encode('not-an-image'));

        return Http::response(parseOrderFixture('list_1')['chat_raw']);
    });

    try {
        (new MuseSpark('test-key', 'http://meta.test'))->parseOrder($shop, null, $path);
    } finally {
        @unlink($path);
    }
});

test('parseOrder rejects empty input without calling the API', function () {
    Http::fake();
    $shop = parseOrderShop();

    foreach ([[null, null], ['', null], ['   ', null]] as [$text, $image]) {
        try {
            (new MuseSpark('test-key', 'http://meta.test'))->parseOrder($shop, $text, $image);
            $this->fail('MuseSparkException was not thrown.');
        } catch (MuseSparkException $e) {
            expect($e->getMessage())->toContain('needs text');
        }
    }
    Http::assertNothingSent();
});

test('parseOrder rejects a missing image without calling the API', function () {
    Http::fake();

    try {
        (new MuseSpark('test-key', 'http://meta.test'))->parseOrder(parseOrderShop(), null, '/nope/missing.jpg');
        $this->fail('MuseSparkException was not thrown.');
    } catch (MuseSparkException $e) {
        expect($e->getMessage())->toContain('Image not found');
    }
    Http::assertNothingSent();
});

test('parseOrder wraps HTTP failures with status and message', function () {
    Http::fake(fn () => Http::response(['error' => ['message' => 'overloaded']], 500));

    try {
        (new MuseSpark('test-key', 'http://meta.test'))->parseOrder(parseOrderShop(), 'hello', null);
        $this->fail('MuseSparkException was not thrown.');
    } catch (MuseSparkException $e) {
        expect($e->getMessage())->toContain('parse_order')->toContain('HTTP 500')->toContain('overloaded');
    }
});

test('parseOrder throws on an unexpected payload', function () {
    Http::fake(fn () => Http::response(['choices' => [['message' => ['content' => 'not json']]]]));

    try {
        (new MuseSpark('test-key', 'http://meta.test'))->parseOrder(parseOrderShop(), 'hello', null);
        $this->fail('MuseSparkException was not thrown.');
    } catch (MuseSparkException $e) {
        expect($e->getMessage())->toContain('unexpected payload');
    }
});

test('parseOrder requires an API key', function () {
    Http::fake();

    try {
        (new MuseSpark('', 'http://meta.test'))->parseOrder(parseOrderShop(), 'hello', null);
        $this->fail('MuseSparkException was not thrown.');
    } catch (MuseSparkException $e) {
        expect($e->getMessage())->toContain('MODEL_API_KEY');
    }
    Http::assertNothingSent();
});
