<?php

use App\Data\Crop;
use App\Data\PhysicalItem;
use App\Services\Catalog\SkuGrouper;

function grouperCrop(string $id, array $embedding, int $w = 100, int $h = 100, int $row = 1, int $order = 1): Crop
{
    return new Crop(
        id: $id, keyframe: 0, cropPath: "scans/s/crops/{$id}.jpg",
        cropWidth: $w, cropHeight: $h, box: [0, 0, 10, 10],
        confidence: 0.9, phrase: 'bottle',
        shelfRow: $row, shelfOrder: $order, embedding: $embedding,
    );
}

function grouperItem(string $id, array $embedding, int $size = 100, int $row = 1, int $order = 1): PhysicalItem
{
    return new PhysicalItem(
        representativeId: $id, memberIds: [$id], embedding: $embedding,
        cropPath: "scans/s/crops/{$id}.jpg",
        cropWidth: $size, cropHeight: $size,
        shelfRow: $row, shelfOrder: $order,
    );
}

test('pass 1 merges same-spot pairs above similarity, transitively', function () {
    $crops = [
        grouperCrop('a', [1, 0, 0], row: 2, order: 7),
        grouperCrop('b', [0.9, 0.4359, 0]),
        grouperCrop('c', [0.62, 0.78, 0], 50, 50),
        grouperCrop('d', [1, 0, 0]),
        grouperCrop('f', [0, 1, 0]),
    ];
    $pairs = [
        ['a' => 'a', 'b' => 'b'],
        ['a' => 'b', 'b' => 'c'],
        ['a' => 'd', 'b' => 'f'],
        ['a' => 'a', 'b' => 'ghost'],
    ];

    $items = (new SkuGrouper)->groupPhysicalItems($crops, $pairs, 0.80);

    // a-b-c merge transitively (a-c alone scores 0.62); d-f stay split at 0.0.
    expect($items)->toHaveCount(3)
        ->and($items[0]->memberIds)->toBe(['a', 'b', 'c'])
        ->and($items[0]->representativeId)->toBe('a')
        ->and($items[0]->shelfRow)->toBe(2)
        ->and($items[0]->shelfOrder)->toBe(7)
        ->and($items[1]->memberIds)->toBe(['d'])
        ->and($items[2]->memberIds)->toBe(['f']);
});

test('pass 1 representative breaks crop-area ties by id', function () {
    $crops = [
        grouperCrop('b', [1, 0, 0]),
        grouperCrop('a', [1, 0, 0]),
    ];

    $items = (new SkuGrouper)->groupPhysicalItems($crops, [['a' => 'a', 'b' => 'b']], 0.80);

    expect($items)->toHaveCount(1)->and($items[0]->representativeId)->toBe('a');
});

test('pass 2 groups facings by representative similarity', function () {
    $items = [
        grouperItem('i1', [1, 0, 0]),
        grouperItem('i2', [0.95, 0.3122, 0], 300, 1, 5),
        grouperItem('i3', [0, 1, 0], 50, 2, 1),
    ];

    $groups = (new SkuGrouper)->groupSkus($items, 0.90);

    expect($groups)->toHaveCount(2);
    [$first, $second] = $groups;
    expect($first->representativeId)->toBe('i2')
        ->and($first->facings)->toBe(2)
        ->and($first->shelfRow)->toBe(1)
        ->and($first->shelfOrder)->toBe(5)
        ->and($first->embedding)->toBe([0.95, 0.3122, 0])
        ->and($second->representativeId)->toBe('i3')
        ->and($second->facings)->toBe(1);
});

test('pass 2 respects a stricter threshold', function () {
    $items = [
        grouperItem('i1', [1, 0, 0]),
        grouperItem('i2', [0.95, 0.3122, 0]),
    ];

    expect((new SkuGrouper)->groupSkus($items, 0.999))->toHaveCount(2);
});

test('empty inputs group to empty outputs', function () {
    $grouper = new SkuGrouper;

    expect($grouper->groupPhysicalItems([], [], 0.80))->toBe([])
        ->and($grouper->groupSkus([], 0.90))->toBe([]);
});
