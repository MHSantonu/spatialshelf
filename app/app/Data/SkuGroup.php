<?php

namespace App\Data;

readonly class SkuGroup
{
    /**
     * @param  list<float>  $embedding
     * @param  list<PhysicalItem>  $items
     */
    public function __construct(
        public string $representativeId,
        public string $cropPath,
        public array $embedding,
        public int $facings,
        public int $shelfRow,
        public int $shelfOrder,
        public int $cropWidth,
        public int $cropHeight,
        public array $items,
    ) {}
}
