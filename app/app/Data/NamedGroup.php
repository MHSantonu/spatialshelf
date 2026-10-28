<?php

namespace App\Data;

readonly class NamedGroup
{
    /**
     * A SKU group plus its resolved name, for the post-reading name merge.
     *
     * @param  array<string, mixed>  $extracted
     * @param  list<string>  $mergedFrom  representativeIds absorbed by this entry
     * @param  list<string>  $mergeReasons  one reason per absorbed entry, same order
     */
    public function __construct(
        public SkuGroup $group,
        public string $name,
        public float $confidence,
        public array $extracted,
        public ?int $matchedProductId,
        public ?float $matchDistance,
        public array $mergedFrom = [],
        public array $mergeReasons = [],
    ) {}
}
