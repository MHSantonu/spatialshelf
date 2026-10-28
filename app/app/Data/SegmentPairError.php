<?php

namespace App\Data;

readonly class SegmentPairError
{
    public function __construct(
        public int $keyframe,
        public string $phrase,
        public string $error,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            (int) $data['keyframe'],
            $data['phrase'],
            $data['error'],
        );
    }
}
