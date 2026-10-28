<?php

use App\Support\Vec;

test('cosine similarity of identical vectors is 1', function () {
    expect(Vec::cosineSimilarity([1, 2, 3], [1, 2, 3]))->toBeGreaterThan(0.999999);
});

test('cosine similarity of orthogonal vectors is 0', function () {
    expect(Vec::cosineSimilarity([1, 0, 0], [0, 1, 0]))->toBe(0.0);
});

test('cosine similarity of opposite vectors is -1', function () {
    expect(Vec::cosineSimilarity([1, 0], [-1, 0]))->toBeLessThan(-0.999999);
});

test('cosine distance is one minus similarity', function () {
    expect(Vec::cosineDistance([1, 0, 0], [0, 1, 0]))->toBe(1.0);
});

test('zero vectors score similarity 0 instead of dividing by zero', function () {
    expect(Vec::cosineSimilarity([0, 0], [1, 1]))->toBe(0.0);
});

test('mismatched lengths throw', function () {
    Vec::cosineSimilarity([1, 0], [1]);
})->throws(InvalidArgumentException::class);
