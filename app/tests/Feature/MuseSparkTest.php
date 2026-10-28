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
