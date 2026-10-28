<?php

namespace App\Data;

readonly class SegmentResult
{
    /**
     * @param  list<string>  $phrases
     * @param  list<int>  $keyframeIndices
     * @param  array<int, int>  $countsPerKeyframe
     * @param  list<SegmentPairError>  $errors
     */
    public function __construct(
        public string $scanId,
        public array $phrases,
        public string $phraseSource,
        public array $keyframeIndices,
        public array $countsPerKeyframe,
        public int $totalDetections,
        public string $detectionsPath,
        public array $errors,
        public OverlayResult $overlay,
        public bool $retried,
        public SegmentTimings $timings,
    ) {}

    public static function fromArray(array $data): self
    {
        $counts = [];
        foreach ($data['counts_per_keyframe'] as $key => $value) {
            $counts[(int) $key] = (int) $value;
        }

        return new self(
            $data['scan_id'],
            array_values($data['phrases']),
            $data['phrase_source'],
            array_map('intval', $data['keyframe_indices']),
            $counts,
            (int) $data['total_detections'],
            $data['detections_path'],
            array_map(SegmentPairError::fromArray(...), $data['errors']),
            OverlayResult::fromArray($data['overlay']),
            (bool) ($data['retried'] ?? false),
            SegmentTimings::fromArray($data['timings']),
        );
    }
}
