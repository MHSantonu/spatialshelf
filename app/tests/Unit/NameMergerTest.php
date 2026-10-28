<?php

use App\Data\NamedGroup;
use App\Data\PhysicalItem;
use App\Data\SkuGroup;
use App\Services\Catalog\NameMerger;

function namedEntry(string $id, string $name, float $confidence, array $embedding, int $size = 100, int $facings = 1): NamedGroup
{
    $item = new PhysicalItem(
        representativeId: $id, memberIds: [$id], embedding: $embedding,
        cropPath: "scans/s/crops/{$id}.jpg",
        cropWidth: $size, cropHeight: $size,
        shelfRow: 1, shelfOrder: 1,
    );

    return new NamedGroup(
        group: new SkuGroup(
            representativeId: $id, cropPath: $item->cropPath, embedding: $embedding,
            facings: $facings, shelfRow: 1, shelfOrder: 1,
            cropWidth: $size, cropHeight: $size, items: [$item],
        ),
        name: $name,
        confidence: $confidence,
        extracted: ['name' => $name, 'category' => 'other', 'confidence' => $confidence],
        matchedProductId: null,
        matchDistance: 1.0,
    );
}

test('merges fuzzy-equal names above similarity, winner by confidence', function () {
    $entries = [
        namedEntry('a', 'Sajib', 0.93, [1, 0, 0]),
        namedEntry('b', 'Sajeeb', 0.71, [0.95, 0.3122, 0], 300),
    ];

    $merged = (new NameMerger)->merge($entries);

    expect($merged)->toHaveCount(1);
    $one = $merged[0];
    expect($one->name)->toBe('Sajib') // highest-confidence read wins, even with the typo
        ->and($one->confidence)->toBe(0.93)
        ->and($one->group->facings)->toBe(2)
        ->and($one->group->cropWidth)->toBe(300) // largest crop kept
        ->and($one->group->representativeId)->toBe('b')
        ->and($one->mergedFrom)->toBe(['b'])
        ->and($one->mergeReasons)->toHaveCount(1)
        ->and($one->mergeReasons[0])->toContain('levenshtein 2');
});

test('merges contained names above similarity', function () {
    $entries = [
        namedEntry('a', 'Tang Orange', 0.8, [1, 0, 0]),
        namedEntry('b', 'Tang', 0.9, [0.9, 0.4359, 0]),
    ];

    $merged = (new NameMerger)->merge($entries);

    expect($merged)->toHaveCount(1)
        ->and($merged[0]->name)->toBe('Tang')
        ->and($merged[0]->mergeReasons[0])->toContain('contained');
});

test('merges transitively through a chain of fuzzy names', function () {
    // Sajib~Sajeeb is levenshtein 2, Sajeeb~Safeeb is 1, Sajib~Safeeb is 3:
    // pairwise the outer pair is blocked, but the chain merges all three.
    $entries = [
        namedEntry('a', 'Sajib', 0.9, [1, 0, 0]),
        namedEntry('b', 'Sajeeb', 0.8, [0.9, 0.4359, 0]),
        namedEntry('c', 'Safeeb', 0.7, [0.62, 0.78, 0]),
    ];

    $merged = (new NameMerger)->merge($entries);

    expect($merged)->toHaveCount(1)
        ->and($merged[0]->name)->toBe('Sajib')
        ->and($merged[0]->group->facings)->toBe(3)
        ->and($merged[0]->mergedFrom)->toBe(['b', 'c']);
});

test('blocks merges below the similarity gate', function () {
    $entries = [
        namedEntry('a', 'Tang', 0.9, [1, 0, 0]),
        namedEntry('b', 'Tang', 0.9, [0, 1, 0]),
    ];

    $merged = (new NameMerger)->merge($entries);

    expect($merged)->toHaveCount(2)
        ->and($merged[0]->mergedFrom)->toBe([])
        ->and($merged[1]->mergedFrom)->toBe([]);
});

test('blocks merges for different names despite high similarity', function () {
    $entries = [
        namedEntry('a', 'Tang Orange', 0.9, [1, 0, 0]),
        namedEntry('b', 'Detergent Bar', 0.9, [1, 0, 0]),
    ];

    expect((new NameMerger)->merge($entries))->toHaveCount(2);
});

test('empty names never merge', function () {
    $entries = [
        namedEntry('a', '', 0.0, [1, 0, 0]),
        namedEntry('b', '', 0.0, [1, 0, 0]),
    ];

    expect((new NameMerger)->merge($entries))->toHaveCount(2);
});

test('normalization ignores case, punctuation and spacing', function () {
    $entries = [
        namedEntry('a', "  Lay's  Chips ", 0.9, [1, 0, 0]),
        namedEntry('b', 'lays chips', 0.8, [1, 0, 0]),
    ];

    $merged = (new NameMerger)->merge($entries);

    expect($merged)->toHaveCount(1)
        ->and($merged[0]->mergeReasons[0])->toContain('identical');
});

test('empty input merges to empty output', function () {
    expect((new NameMerger)->merge([]))->toBe([]);
});

test('identical names merge through the fast path below 0.70', function () {
    $entries = [
        namedEntry('a', 'Tang', 0.9, [1, 0, 0]),
        namedEntry('b', 'TANG', 0.8, [0.6, 0.8, 0]),
    ];

    $merged = (new NameMerger)->merge($entries);

    expect($merged)->toHaveCount(1)
        ->and($merged[0]->name)->toBe('Tang')
        ->and($merged[0]->mergeReasons[0])->toContain('fast path');
});

test('identical names still block below 0.55', function () {
    $entries = [
        namedEntry('a', 'Tang', 0.9, [1, 0, 0]),
        namedEntry('b', 'Tang', 0.8, [0.5, 0.866, 0]),
    ];

    expect((new NameMerger)->merge($entries))->toHaveCount(2);
});

test('fuzzy names keep the 0.70 gate', function () {
    // Sajib~Sajeeb is levenshtein 2; 0.65 clears the fast path but must
    // not merge without an identical name.
    $entries = [
        namedEntry('a', 'Sajib', 0.9, [1, 0, 0]),
        namedEntry('b', 'Sajeeb', 0.8, [0.65, 0.76, 0]),
    ];

    expect((new NameMerger)->merge($entries))->toHaveCount(2);
});

test('brandless reads merge on vision alone above 0.70', function () {
    $entries = [
        namedEntry('a', 'Tang Orange', 0.9, [1, 0, 0]),
        namedEntry('b', 'Orange carton (brand unclear)', 0.25, [0.75, 0.66, 0]),
    ];

    $merged = (new NameMerger)->merge($entries);

    expect($merged)->toHaveCount(1)
        ->and($merged[0]->name)->toBe('Tang Orange')
        ->and($merged[0]->mergeReasons[0])->toContain('defers to vision');
});

test('brandless reads block below 0.70', function () {
    $entries = [
        namedEntry('a', 'Tang Orange', 0.9, [1, 0, 0]),
        namedEntry('b', 'Orange carton (brand unclear)', 0.25, [0.65, 0.76, 0]),
    ];

    expect((new NameMerger)->merge($entries))->toHaveCount(2);
});

test('"variant unclear" keeps its brand and never waives', function () {
    // Different brands at 0.72 must stay split: the waiver is only for
    // reads with no brand at all.
    $entries = [
        namedEntry('a', 'Safeeb Drink (variant unclear)', 0.45, [1, 0, 0]),
        namedEntry('b', 'Unilever GlucoMax D', 0.82, [0.72, 0.69, 0]),
    ];

    expect((new NameMerger)->merge($entries))->toHaveCount(2);
});
