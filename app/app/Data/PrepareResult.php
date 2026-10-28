<?php

namespace App\Data;

readonly class PrepareResult
{
    /**
     * @param  list<int>  $sourceFrameIndices
     * @param  list<string>  $keyframes
     * @param  list<string>  $keyframesFull
     */
    public function __construct(
        public string $scanId,
        public string $clipPath,
        public int $clipWidth,
        public int $clipHeight,
        public int $fullWidth,
        public int $fullHeight,
        public int $frameCount,
        public float $duration,
        public int $fps,
        public int $rotation,
        public int $sourceFrameCount,
        public array $sourceFrameIndices,
        public array $keyframes,
        public array $keyframesFull,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            $data['scan_id'],
            $data['clip_path'],
            (int) $data['clip_width'],
            (int) $data['clip_height'],
            (int) $data['full_width'],
            (int) $data['full_height'],
            (int) $data['frame_count'],
            (float) $data['duration'],
            (int) $data['fps'],
            (int) $data['rotation'],
            (int) $data['source_frame_count'],
            array_map('intval', $data['source_frame_indices']),
            array_values($data['keyframes']),
            array_values($data['keyframes_full']),
        );
    }
}
