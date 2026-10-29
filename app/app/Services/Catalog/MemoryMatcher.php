<?php

namespace App\Services\Catalog;

use App\Data\NearestMatch;
use App\Models\ProductImage;
use App\Support\Vec;

final class MemoryMatcher implements VectorIndex
{
    /** @var array<int, list<array{product_id: int, embedding: list<float>}>> */
    private static array $cache = [];

    /**
     * @param  list<array{product_id: int, embedding: list<float>}>|null  $entries
     */
    public function __construct(
        private readonly int $shopId,
        ?array $entries = null,
        private readonly ?float $maxDistance = null,
    ) {
        if ($entries !== null) {
            self::$cache[$this->shopId] = array_values($entries);
        }
    }

    /** @param  list<float>  $vector */
    public function match(array $vector): NearestMatch
    {
        $bestId = null;
        $bestDistance = INF;
        foreach ($this->entries() as $entry) {
            $distance = Vec::cosineDistance($vector, $entry['embedding']);
            if ($distance < $bestDistance) {
                $bestDistance = $distance;
                $bestId = $entry['product_id'];
            }
        }

        $threshold = $this->maxDistance ?? (float) config('spatialshelf.match_distance', 0.15);
        if ($bestId === null || $bestDistance > $threshold) {
            return new NearestMatch(null, $bestId === null ? 2.0 : $bestDistance);
        }

        return new NearestMatch($bestId, $bestDistance);
    }

    /**
     * Best cosine distance per product, best-first, at most two.
     *
     * Unlike match(), this never applies the threshold: callers decide what
     * counts as a hit, and a near-tied runner-up still matters even when it
     * sits just past the line.
     *
     * @param  list<float>  $vector
     * @return list<array{product_id: int, distance: float}>
     */
    public function topTwo(array $vector): array
    {
        /** @var array<int, float> $best */
        $best = [];
        foreach ($this->entries() as $entry) {
            $distance = Vec::cosineDistance($vector, $entry['embedding']);
            $id = $entry['product_id'];
            if (! isset($best[$id]) || $distance < $best[$id]) {
                $best[$id] = $distance;
            }
        }
        asort($best);
        $out = [];
        foreach ($best as $id => $distance) {
            $out[] = ['product_id' => $id, 'distance' => $distance];
            if (count($out) === 2) {
                break;
            }
        }

        return $out;
    }

    public static function bust(int $shopId): void
    {
        unset(self::$cache[$shopId]);
    }

    public static function flush(): void
    {
        self::$cache = [];
    }

    /** @return list<array{product_id: int, embedding: list<float>}> */
    private function entries(): array
    {
        return self::$cache[$this->shopId] ??= $this->loadEntries();
    }

    /** @return list<array{product_id: int, embedding: list<float>}> */
    private function loadEntries(): array
    {
        return ProductImage::query()
            ->select(['product_id', 'embedding'])
            ->whereNotNull('embedding')
            ->whereHas('product', fn ($query) => $query
                ->where('shop_id', $this->shopId)
                ->where('active', true))
            ->orderBy('product_images.id')
            ->get()
            ->map(fn (ProductImage $image) => [
                'product_id' => (int) $image->product_id,
                'embedding' => array_values((array) $image->embedding),
            ])
            ->filter(fn (array $entry) => $entry['embedding'] !== [])
            ->values()
            ->all();
    }
}
