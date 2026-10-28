<?php

namespace App\Data;

readonly class NearestMatch
{
    public function __construct(
        public ?int $productId,
        public float $distance,
    ) {}

    public function matched(): bool
    {
        return $this->productId !== null;
    }
}
