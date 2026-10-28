<?php

namespace App\Services\Catalog;

use App\Data\Crop;
use App\Data\PhysicalItem;
use App\Data\SkuGroup;
use App\Support\Vec;

final class SkuGrouper
{
    public const SAME_SPOT_SIMILARITY = 0.80;

    /**
     * Pass 1: cluster same-spot detections of one physical item.
     *
     * @param  list<Crop>  $crops
     * @param  list<array{a: string, b: string}>  $pairs
     * @return list<PhysicalItem>
     */
    public function groupPhysicalItems(array $crops, array $pairs, float $minSimilarity = self::SAME_SPOT_SIMILARITY): array
    {
        $byId = [];
        foreach ($crops as $crop) {
            $byId[$crop->id] = $crop;
        }
        ksort($byId);

        $union = new UnionFind(array_keys($byId));
        foreach ($pairs as $pair) {
            $a = $pair['a'] ?? null;
            $b = $pair['b'] ?? null;
            if (! isset($byId[$a], $byId[$b])) {
                continue;
            }
            if (Vec::cosineSimilarity($byId[$a]->embedding, $byId[$b]->embedding) >= $minSimilarity) {
                $union->union($a, $b);
            }
        }

        return array_map(
            fn (array $memberIds) => $this->physicalItem(array_map(fn (string $id) => $byId[$id], $memberIds)),
            $union->groups()
        );
    }

    /**
     * Pass 2: cluster physical items into SKU facings by representative embedding.
     *
     * @param  list<PhysicalItem>  $items
     * @return list<SkuGroup>
     */
    public function groupSkus(array $items, ?float $minSimilarity = null): array
    {
        $minSimilarity ??= (float) config('spatialshelf.group_threshold', 0.90);

        $byId = [];
        foreach ($items as $item) {
            $byId[$item->representativeId] = $item;
        }
        ksort($byId);
        $ids = array_keys($byId);

        $union = new UnionFind($ids);
        $count = count($ids);
        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                if (Vec::cosineSimilarity($byId[$ids[$i]]->embedding, $byId[$ids[$j]]->embedding) >= $minSimilarity) {
                    $union->union($ids[$i], $ids[$j]);
                }
            }
        }

        return array_map(
            fn (array $repIds) => $this->skuGroup(array_map(fn (string $id) => $byId[$id], $repIds)),
            $union->groups()
        );
    }

    /** @param  list<Crop>  $members */
    private function physicalItem(array $members): PhysicalItem
    {
        usort($members, fn (Crop $a, Crop $b) => [$b->cropWidth * $b->cropHeight, $a->id]
            <=> [$a->cropWidth * $a->cropHeight, $b->id]);
        $rep = $members[0];
        $ids = array_map(fn (Crop $crop) => $crop->id, $members);
        sort($ids);

        return new PhysicalItem(
            representativeId: $rep->id,
            memberIds: $ids,
            embedding: $rep->embedding,
            cropPath: $rep->cropPath,
            cropWidth: $rep->cropWidth,
            cropHeight: $rep->cropHeight,
            shelfRow: $rep->shelfRow,
            shelfOrder: $rep->shelfOrder,
        );
    }

    /** @param  list<PhysicalItem>  $items */
    private function skuGroup(array $items): SkuGroup
    {
        usort($items, fn (PhysicalItem $a, PhysicalItem $b) => [$b->cropWidth * $b->cropHeight, $a->representativeId]
            <=> [$a->cropWidth * $a->cropHeight, $b->representativeId]);
        $rep = $items[0];
        usort($items, fn (PhysicalItem $a, PhysicalItem $b) => $a->representativeId <=> $b->representativeId);

        return new SkuGroup(
            representativeId: $rep->representativeId,
            cropPath: $rep->cropPath,
            embedding: $rep->embedding,
            facings: count($items),
            shelfRow: $rep->shelfRow,
            shelfOrder: $rep->shelfOrder,
            cropWidth: $rep->cropWidth,
            cropHeight: $rep->cropHeight,
            items: $items,
        );
    }
}
