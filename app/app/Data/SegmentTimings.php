<?php

namespace App\Data;

readonly class SegmentTimings
{
    public function __construct(
        public float $totalSeconds,
        public ?float $pickPhrasesSeconds,
        public float $imageSeconds,
        public ?float $overlaySeconds,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            (float) $data['total_seconds'],
            isset($data['pick_phrases_seconds']) ? (float) $data['pick_phrases_seconds'] : null,
            (float) $data['image_seconds'],
            isset($data['overlay_seconds']) ? (float) $data['overlay_seconds'] : null,
        );
    }
}
