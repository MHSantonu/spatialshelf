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
