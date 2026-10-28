<?php

namespace App\Data;

readonly class Crop
{
    /**
     * @param  list<int>  $box
     * @param  list<float>  $embedding
     */
    public function __construct(
        public string $id,
        public int $keyframe,
        public string $cropPath,
        public int $cropWidth,
        public int $cropHeight,
        public array $box,
        public float $confidence,
        public string $phrase,
        public int $shelfRow,
        public int $shelfOrder,
        public array $embedding,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            $data['id'],
            (int) $data['keyframe'],
            $data['crop_path'],
            (int) $data['crop_width'],
            (int) $data['crop_height'],
            array_map('intval', $data['box']),
            (float) $data['confidence'],
            $data['phrase'],
            (int) $data['shelf_row'],
            (int) $data['shelf_order'],
            array_map('floatval', $data['embedding']),
        );
    }
}
