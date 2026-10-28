<?php

namespace App\Console\Commands;

use App\Models\Scan;
use App\Support\Vec;
use Illuminate\Console\Command;

class ScanCalibrateCommand extends Command
{
    protected $signature = 'scan:calibrate {scanA : Scan id treated as queries} {scanB : Scan id treated as memory}';

    protected $description = 'Print nearest-neighbour cosine distances between two scans of one shelf.';

    private const BUCKET_EDGES = [0, 0.05, 0.10, 0.15, 0.20, 0.25, 0.30, 0.40, 0.50, 0.75, 1.0, 2.01];

    public function handle(): int
    {
        $scanA = Scan::with('items')->find($this->argument('scanA'));
        $scanB = Scan::with('items')->find($this->argument('scanB'));
        if ($scanA === null || $scanB === null) {
            $missing = $scanA === null ? $this->argument('scanA') : $this->argument('scanB');
            $this->error("Scan not found: {$missing}");

            return self::FAILURE;
        }

        [$from, $skippedFrom] = $this->embeddings($scanA);
        [$to, $skippedTo] = $this->embeddings($scanB);
        if ($from === [] || $to === []) {
            $this->error('Both scans need items with embeddings.');

            return self::FAILURE;
        }

        $distances = [];
        foreach ($from as $vector) {
            $best = INF;
            foreach ($to as $other) {
                $best = min($best, Vec::cosineDistance($vector, $other));
            }
            $distances[] = $best;
        }
        sort($distances);

        $this->info("Nearest-neighbour distances: scan {$scanA->id} (".count($from).' items) -> '."scan {$scanB->id} (".count($to).' items)');
        if ($skippedFrom > 0 || $skippedTo > 0) {
            $this->line("Skipped without embeddings: {$skippedFrom} in A, {$skippedTo} in B.");
        }
        $buckets = $this->histogram($distances);
        $peak = max($buckets);
        $this->table(
            ['Distance', 'Count', ''],
            array_map(
                fn ($label, $count) => [$label, $count, $peak > 0 ? str_repeat('#', (int) round(30 * $count / $peak)) : ''],
                array_keys($buckets),
                $buckets
            )
        );

        $count = count($distances);
        $this->line(sprintf(
            'n=%d min=%.3f p50=%.3f mean=%.3f p90=%.3f max=%.3f',
            $count, $distances[0], $this->percentile($distances, 50),
            array_sum($distances) / $count, $this->percentile($distances, 90),
            $distances[$count - 1]
        ));
        if ($count >= 2) {
            $gap = 0.0;
            $cut = 0.0;
            for ($i = 1; $i < $count; $i++) {
                if ($gap < $distances[$i] - $distances[$i - 1]) {
                    $gap = $distances[$i] - $distances[$i - 1];
                    $cut = ($distances[$i] + $distances[$i - 1]) / 2;
                }
            }
            $this->line(sprintf(
                'Largest gap: %.3f -> %.3f; consider match_distance %.2f (group_threshold %.2f).',
                $cut - $gap / 2, $cut + $gap / 2, $cut, 1 - $cut
            ));
        }

        return self::SUCCESS;
    }

    private function embeddings(Scan $scan): array
    {
        $vectors = [];
        $skipped = 0;
        foreach ($scan->items as $item) {
            if (is_array($item->embedding) && $item->embedding !== []) {
                $vectors[] = array_values($item->embedding);
            } else {
                $skipped++;
            }
        }

        return [$vectors, $skipped];
    }

    private function histogram(array $distances): array
    {
        $edges = self::BUCKET_EDGES;
        $buckets = [];
        for ($i = 0; $i < count($edges) - 1; $i++) {
            $buckets[sprintf('%.2f-%.2f', $edges[$i], $edges[$i + 1])] = 0;
        }
        foreach ($distances as $distance) {
            for ($i = 0; $i < count($edges) - 1; $i++) {
                if ($distance >= $edges[$i] && $distance < $edges[$i + 1]) {
                    $label = sprintf('%.2f-%.2f', $edges[$i], $edges[$i + 1]);
                    $buckets[$label]++;
                    break;
                }
            }
        }

        return $buckets;
    }

    private function percentile(array $sorted, int $percent): float
    {
        return $sorted[max(0, (int) ceil($percent / 100 * count($sorted)) - 1)];
    }
}
