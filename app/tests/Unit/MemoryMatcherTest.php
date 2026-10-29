<?php

use App\Services\Catalog\MemoryMatcher;

beforeEach(fn () => MemoryMatcher::flush());

function matcherEntries(): array
{
    return [
        ['product_id' => 5, 'embedding' => [1, 0, 0]],
        ['product_id' => 9, 'embedding' => [0, 1, 0]],
    ];
}

test('match returns the nearest product within threshold', function () {
    $match = (new MemoryMatcher(1, matcherEntries(), 0.15))->match([0.99, 0.141, 0]);

    expect($match->matched())->toBeTrue()
        ->and($match->productId)->toBe(5)
        ->and($match->distance)->toBeGreaterThan(0.009)
        ->and($match->distance)->toBeLessThan(0.011);
});

test('match beyond threshold keeps the distance but no product', function () {
    $match = (new MemoryMatcher(1, matcherEntries(), 0.15))->match([0, 0, 1]);

    expect($match->matched())->toBeFalse()
        ->and($match->productId)->toBeNull()
        ->and($match->distance)->toBe(1.0);
});

test('match on empty memory returns distance 2', function () {
    $match = (new MemoryMatcher(2, [], 0.15))->match([1, 0, 0]);

    expect($match->matched())->toBeFalse()
        ->and($match->distance)->toBe(2.0);
});

test('topTwo returns distinct products best-first without thresholding', function () {
    $entries = [
        ['product_id' => 5, 'embedding' => [1, 0, 0]],
        ['product_id' => 5, 'embedding' => [0.9, 0.436, 0]],
        ['product_id' => 9, 'embedding' => [0, 1, 0]],
    ];

    $top = (new MemoryMatcher(1, $entries, 0.15))->topTwo([1, 0, 0]);

    expect($top)->toHaveCount(2)
        ->and($top[0]['product_id'])->toBe(5)
        ->and($top[0]['distance'])->toBe(0.0)
        ->and($top[1]['product_id'])->toBe(9)
        ->and($top[1]['distance'])->toBe(1.0);
});

test('bust clears the cached entries', function () {
    new MemoryMatcher(7, matcherEntries());

    MemoryMatcher::bust(7);

    $cache = (new ReflectionProperty(MemoryMatcher::class, 'cache'))->getValue();
    expect($cache)->toBe([]);
});
