<?php

namespace App\Data;

readonly class EmbedResult
{
    /** @param  list<float>  $embedding */
    public function __construct(
        public string $imagePath,
        public array $embedding,
        public int $dim,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            $data['image_path'],
            array_map('floatval', $data['embedding']),
            (int) $data['dim'],
        );
    }
}
