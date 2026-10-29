<?php

use App\Exceptions\MuseSparkException;
use App\Services\Meta\MuseSpark;
use App\Services\Prompts;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

function sparkCropFiles(array $contents, Closure $run): mixed
{
    $paths = [];
    try {
        foreach ($contents as $bytes) {
            $path = tempnam(sys_get_temp_dir(), 'crop');
            file_put_contents($path, $bytes);
            $paths[] = $path;
        }

        return $run($paths);
    } finally {
        foreach ($paths as $path) {
            @unlink($path);
        }
    }
}

function sparkItemsResponse(array $indices): array
{
    return ['choices' => [['message' => ['content' => json_encode(['items' => array_map(
        fn ($i) => [
            'image_index' => $i, 'name' => "Product {$i}", 'brand' => 'Acme',
            'size_value' => 0, 'size_unit' => 'unknown', 'category' => 'snacks',
            'mrp_text' => '', 'confidence' => 0.9,
        ],
        $indices
    )])]]]];
}

test('readCrops sends numbered data URLs and returns items keyed by index', function () {
    Http::fake(function (Request $request) {
        $payload = $request->data();
        expect($payload['model'])->toBe('muse-spark-1.3')
            ->and($payload['messages'][0])->toBe(['role' => 'system', 'content' => Prompts::text('read_crops')])
            ->and($payload['messages'][1]['content'][0])->toBe(['type' => 'text', 'text' => 'Image 1'])
            ->and($payload['messages'][1]['content'][1]['image_url']['url'])
            ->toBe('data:image/jpeg;base64,'.base64_encode('bytes-one'))
            ->and($payload['messages'][1]['content'][3]['image_url']['url'])
            ->toBe('data:image/jpeg;base64,'.base64_encode('bytes-two'))
            ->and($payload['response_format']['json_schema']['name'])->toBe('read_crops')
            ->and($payload['response_format']['json_schema']['schema'])->toBe(Prompts::schema('read_crops'));

        return Http::response(sparkItemsResponse([1, 2]));
    });

    $result = sparkCropFiles(['bytes-one', 'bytes-two'],
        fn ($paths) => (new MuseSpark('test-key', 'http://meta.test'))->readCrops($paths));

    expect($result['items'])->toHaveKeys([1, 2])
        ->and($result['items'][2]['name'])->toBe('Product 2')
        ->and($result['raw'])->toHaveCount(1)
        ->and($result['raw'][0]['choices'][0]['message']['content'])->toBeString();
});

test('readCrops batches of 8 merge into global indices', function () {
    Http::fake(function (Request $request) {
        $content = $request['messages'][1]['content'];
        $images = count(array_filter($content, fn ($part) => ($part['type'] ?? null) === 'image_url'));

        return Http::response(sparkItemsResponse(range(1, $images)));
    });

    $result = sparkCropFiles(array_fill(0, 10, 'x'),
        fn ($paths) => (new MuseSpark('test-key', 'http://meta.test'))->readCrops($paths));

    expect(array_keys($result['items']))->toBe(range(1, 10))
        ->and($result['raw'])->toHaveCount(2);
});

test('readCrops retries a batch with a bad image_index and recovers', function () {
    $calls = 0;
    Http::fake(function () use (&$calls) {
        $calls++;

        return Http::response(sparkItemsResponse($calls === 1 ? [2, 2] : [1, 2]));
    });

    $result = sparkCropFiles(['x', 'y'],
        fn ($paths) => (new MuseSpark('test-key', 'http://meta.test'))->readCrops($paths));

    expect($result['items'])->toHaveKeys([1, 2])
        ->and($result['retries'])->toBe([0])
        ->and($result['solos'])->toBe([])
        ->and($result['raw'])->toHaveCount(2);
});

test('readCrops falls back to solo reads when a batch keeps failing', function () {
    Http::fake(function (Request $request) {
        $content = $request['messages'][1]['content'];
        $images = count(array_filter($content, fn ($part) => ($part['type'] ?? null) === 'image_url'));
        if ($images > 1) {
            return Http::response(sparkItemsResponse([1, 1]));
        }

        return Http::response(sparkItemsResponse([7]));
    });

    $result = sparkCropFiles(['x', 'y'],
        fn ($paths) => (new MuseSpark('test-key', 'http://meta.test'))->readCrops($paths));

    // Solo items map positionally; the returned index is ignored.
    expect($result['items'])->toHaveKeys([1, 2])
        ->and($result['retries'])->toBe([0])
        ->and($result['solos'])->toBe([0])
        ->and($result['raw'])->toHaveCount(4);
});

test('readCrops throws when even a solo read is garbage', function () {
    Http::fake(function (Request $request) {
        $content = $request['messages'][1]['content'];
        $images = count(array_filter($content, fn ($part) => ($part['type'] ?? null) === 'image_url'));

        return $images > 1
            ? Http::response(sparkItemsResponse([1, 1]))
            : Http::response(['choices' => [['message' => ['content' => 'not json']]]]);
    });

    sparkCropFiles(['x', 'y'], function ($paths) {
        try {
            (new MuseSpark('test-key', 'http://meta.test'))->readCrops($paths);
            $this->fail('MuseSparkException was not thrown.');
        } catch (MuseSparkException $e) {
            expect($e->getMessage())->toContain('solo image 1');
        }
    });
});

test('readCrops wraps HTTP failures with status and message', function () {
    Http::fake(fn () => Http::response(['error' => ['message' => 'overloaded']], 500));

    sparkCropFiles(['x'], function ($paths) {
        try {
            (new MuseSpark('test-key', 'http://meta.test'))->readCrops($paths);
            $this->fail('MuseSparkException was not thrown.');
        } catch (MuseSparkException $e) {
            expect($e->getMessage())->toContain('HTTP 500')->toContain('overloaded');
        }
    });
});

test('readCrops rejects a missing crop file without calling the API', function () {
    Http::fake();

    try {
        (new MuseSpark('test-key', 'http://meta.test'))->readCrops(['/nope/missing.jpg']);
        $this->fail('MuseSparkException was not thrown.');
    } catch (MuseSparkException $e) {
        expect($e->getMessage())->toContain('Crop not found');
    }
    Http::assertNothingSent();
});

test('readCrops returns empty for empty input without calling the API', function () {
    Http::fake();

    expect((new MuseSpark('test-key', 'http://meta.test'))->readCrops([]))
        ->toBe(['items' => [], 'raw' => [], 'retries' => [], 'solos' => []]);
    Http::assertNothingSent();
});

test('classifyImage returns the kind with the raw response', function () {
    Http::fake(function (Request $request) {
        $payload = $request->data();
        expect($payload['model'])->toBe('muse-spark-1.3')
            ->and($payload['messages'][0])->toBe(['role' => 'system', 'content' => Prompts::text('classify_image')])
            ->and($payload['response_format']['json_schema']['name'])->toBe('classify_image')
            ->and($payload['response_format']['json_schema']['schema'])->toBe(Prompts::schema('classify_image'));

        return Http::response(['choices' => [['message' => ['content' => json_encode(['kind' => 'single_product'])]]]]);
    });

    $result = sparkCropFiles(['bytes'], fn ($paths) => (new MuseSpark('test-key', 'http://meta.test'))->classifyImage($paths[0]));

    expect($result['kind'])->toBe('single_product')
        ->and($result['raw']['choices'][0]['message']['content'])->toBeString();
});

test('classifyImage fails open to list on an unknown kind', function () {
    Http::fake(fn () => Http::response(['choices' => [['message' => ['content' => json_encode(['kind' => 'mystery'])]]]]));

    $result = sparkCropFiles(['bytes'], fn ($paths) => (new MuseSpark('test-key', 'http://meta.test'))->classifyImage($paths[0]));

    expect($result['kind'])->toBe('list');
});

test('parseVoicePrice sends the prompt and schema with the transcript and product', function () {
    Http::fake(function (Request $request) {
        $payload = $request->data();
        expect($payload['model'])->toBe('muse-spark-1.3')
            ->and($payload['messages'][0])->toBe(['role' => 'system', 'content' => Prompts::text('voice_price')])
            ->and($payload['messages'][1]['content'])->toContain('Tang Orange')
            ->and($payload['messages'][1]['content'])->toContain('chhoy sho ponchash')
            ->and($payload['response_format']['json_schema']['name'])->toBe('voice_price')
            ->and($payload['response_format']['json_schema']['schema'])->toBe(Prompts::schema('voice_price'));

        return Http::response(['choices' => [['message' => ['content' => json_encode([
            'price' => 650, 'size_value' => null, 'size_unit' => 'unknown', 'confidence' => 0.9,
        ])]]]]);
    });

    $result = (new MuseSpark('test-key', 'http://meta.test'))->parseVoicePrice('chhoy sho ponchash', 'Tang Orange');

    expect($result['price'])->toBe(650.0)
        ->and($result['size_value'])->toBeNull()
        ->and($result['size_unit'])->toBe('unknown')
        ->and($result['confidence'])->toBe(0.9)
        ->and($result['raw']['choices'])->toBeArray();
});

test('parseVoicePrice sanitizes numbers and units without guessing', function () {
    Http::fake(fn () => Http::response(['choices' => [['message' => ['content' => json_encode([
        'price' => -5, 'size_value' => 'half', 'size_unit' => 'bottle', 'confidence' => 0.4,
    ])]]]]));

    $result = (new MuseSpark('test-key', 'http://meta.test'))->parseVoicePrice('something');

    expect($result['price'])->toBeNull()
        ->and($result['size_value'])->toBeNull()
        ->and($result['size_unit'])->toBe('unknown')
        ->and($result['confidence'])->toBe(0.4);
});

test('parseVoicePrices maps rows by live item ids and drops the rest', function () {
    Http::fake(function (Request $request) {
        $payload = $request->data();
        $schema = $payload['response_format']['json_schema'];
        expect($schema['name'])->toBe('voice_prices')
            ->and($schema['schema']['properties']['items']['items']['properties']['item_id']['enum'])->toBe(['11', '12', 'none'])
            ->and($payload['messages'][0]['content'])->toContain("11: Horlicks\n12: Tang");

        $content = json_encode(['items' => [
            ['item_id' => '11', 'price' => 650, 'size_value' => null, 'size_unit' => 'unknown', 'confidence' => 0.9],
            ['item_id' => 'none', 'price' => 10, 'size_value' => null, 'size_unit' => 'unknown', 'confidence' => 0.3],
            ['item_id' => '99', 'price' => 10, 'size_value' => null, 'size_unit' => 'unknown', 'confidence' => 0.3],
            ['item_id' => '11', 'price' => 700, 'size_value' => null, 'size_unit' => 'unknown', 'confidence' => 0.5],
            ['item_id' => '12', 'price' => 450, 'size_value' => 0.5, 'size_unit' => 'kg', 'confidence' => 0.88],
        ]]);

        return Http::response(['choices' => [['message' => ['content' => $content]]]]);
    });

    $result = (new MuseSpark('test-key', 'http://meta.test'))->parseVoicePrices(
        'Horlicks six fifty, Tang four fifty',
        [['id' => 11, 'name' => 'Horlicks'], ['id' => 12, 'name' => 'Tang']]
    );

    expect($result['items'])->toBe([
        ['item_id' => 11, 'price' => 650.0, 'size_value' => null, 'size_unit' => 'unknown', 'confidence' => 0.9],
        ['item_id' => 12, 'price' => 450.0, 'size_value' => 0.5, 'size_unit' => 'kg', 'confidence' => 0.88],
    ])->and($result['raw']['choices'])->toBeArray();
});
