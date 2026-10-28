<?php

namespace App\Data;

readonly class CropsResult
{
    /** @param  list<Crop>  $crops */
    public function __construct(
        public string $scanId,
        public array $crops,
        public int $totalCrops,
        public int $skippedEdge,
        public int $rows,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            $data['scan_id'],
            array_map(Crop::fromArray(...), $data['crops']),
            (int) $data['total_crops'],
            (int) $data['skipped_edge'],
            (int) $data['rows'],
        );
    }
}
