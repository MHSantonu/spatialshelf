<?php

namespace App\Data;

readonly class PhysicalItem
{
    /**
     * @param  list<string>  $memberIds
     * @param  list<float>  $embedding
     */
    public function __construct(
        public string $representativeId,
        public array $memberIds,
        public array $embedding,
        public string $cropPath,
        public int $cropWidth,
        public int $cropHeight,
        public int $shelfRow,
        public int $shelfOrder,
    ) {}
}
