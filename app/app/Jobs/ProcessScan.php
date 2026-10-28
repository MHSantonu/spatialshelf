<?php

namespace App\Jobs;

use App\Data\NamedGroup;
use App\Enums\ScanItemDecision;
use App\Enums\ScanStatus;
use App\Models\Product;
use App\Models\Scan;
use App\Services\Catalog\MemoryMatcher;
use App\Services\Catalog\NameMerger;
use App\Services\Catalog\SkuGrouper;
use App\Services\Meta\MuseSpark;
use App\Services\WorkerClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ProcessScan implements ShouldQueue
{
    use Queueable;

    public $tries = 1;

    public $timeout = 300;

    public function __construct(public readonly int $scanId)
    {
        $this->queue = 'scans';
    }

    public function handle(): void
    {
        $scan = Scan::findOrFail($this->scanId);
        $scan->started_at ??= now();

        $workerId = (string) $scan->id;
        $worker = new WorkerClient;
        $started = microtime(true);
        $timings = [];

        try {
            // 1. Preparing video.
            $step = microtime(true);
            $this->mark($scan, ScanStatus::Processing, 'Preparing video', $timings);
            $prepared = $worker->prepare($workerId, $scan->video_path);
            $scan->frames_processed = $prepared->frameCount;
            $timings['prepare'] = $this->elapsed($step);

            // 2-3. Segment is one worker call with two progress updates.
            $step = microtime(true);
            $this->mark($scan, ScanStatus::Processing, 'Choosing what to look for', $timings);
            $segmented = $worker->segment($workerId);
            $timings['segment'] = $this->elapsed($step);
            $this->mark($scan, ScanStatus::Processing, 'Finding products', $timings, [
                'phrases' => $segmented->phrases,
                'object_count' => $segmented->totalDetections,
                'mode_used' => 'image',
                'retried' => $segmented->retried,
            ]);

            // 4. Cutting out products.
            $step = microtime(true);
            $this->mark($scan, ScanStatus::Processing, 'Cutting out products', $timings);
            $crops = $worker->crops($workerId);
            $timings['crops'] = $this->elapsed($step);

            // 5. Grouping + visual memory.
            $step = microtime(true);
            $this->mark($scan, ScanStatus::Processing, 'Checking memory', $timings);
            $grouper = new SkuGrouper;
            $items = $grouper->groupPhysicalItems($crops->crops, $this->sameSpotPairs($workerId));
            $groups = $grouper->groupSkus($items);
            $matcher = new MemoryMatcher($scan->shop_id);
            $matches = [];
            foreach ($groups as $i => $group) {
                $matches[$i] = $matcher->match($group->embedding);
            }
            $timings['memory'] = $this->elapsed($step);

            // 6. Reading labels (unmatched groups only).
            $step = microtime(true);
            $this->mark($scan, ScanStatus::Processing, 'Reading labels', $timings);
            $unmatchedGroups = [];
            $unmatchedPaths = [];
            foreach ($groups as $i => $group) {
                if (! $matches[$i]->matched()) {
                    $unmatchedGroups[] = $i;
                    $unmatchedPaths[] = $group->cropPath;
                }
            }
            $read = (new MuseSpark)->readCrops($unmatchedPaths);
            $readings = [];
            foreach ($read['items'] as $globalIndex => $item) {
                $readings[$unmatchedGroups[$globalIndex - 1]] = $item;
            }
            $timings['read'] = $this->elapsed($step);

            // 7. Name-aware merge, then one scan_item per group.
            $step = microtime(true);
            $named = [];
            foreach ($groups as $i => $group) {
                $match = $matches[$i];
                if ($match->matched()) {
                    $product = Product::findOrFail($match->productId);
                    $extracted = [
                        'name' => $product->name,
                        'brand' => $product->brand,
                        'size_value' => $product->size_value !== null ? (float) $product->size_value : 0,
                        'size_unit' => $product->size_unit ?? 'unknown',
                        'category' => $product->category,
                        'mrp_text' => null,
                        'confidence' => 1.0,
                    ];
                } else {
                    $extracted = $readings[$i];
                }
                $named[] = new NamedGroup(
                    group: $group,
                    name: (string) ($extracted['name'] ?? ''),
                    confidence: (float) ($extracted['confidence'] ?? 0.0),
                    extracted: $extracted,
                    matchedProductId: $match->productId,
                    matchDistance: $match->distance,
                );
            }
            $merged = (new NameMerger)->merge($named);
            $mergeLog = [];
            foreach ($merged as $entry) {
                foreach ($entry->mergedFrom as $k => $fromId) {
                    $mergeLog[] = "merged {$fromId} into {$entry->group->representativeId} ({$entry->mergeReasons[$k]})";
                }
            }
            foreach ($mergeLog as $line) {
                Log::info("scan {$scan->id} name merge: {$line}");
            }
            if ($read['retries'] !== []) {
                Log::warning("scan {$scan->id} read retries", ['retries' => $read['retries'], 'solos' => $read['solos']]);
            }
            $this->mark($scan, ScanStatus::Processing, 'Saving results', $timings, [
                'spark_raw' => $read['raw'],
                'read_retries' => $read['retries'],
                'read_solos' => $read['solos'],
                'name_merges' => $mergeLog,
            ]);
            $depths = $scan->shop->depth_defaults ?? [];
            $rows = [];
            foreach ($merged as $entry) {
                $group = $entry->group;
                $category = $entry->extracted['category'] ?? 'other';
                $rows[] = [
                    'crop_path' => $group->cropPath,
                    'embedding' => $group->embedding,
                    'facings' => $group->facings,
                    'est_qty' => (int) ($group->facings * ($depths[$category] ?? 1)),
                    'shelf_row' => $group->shelfRow,
                    'shelf_order' => $group->shelfOrder,
                    'extracted' => $entry->extracted,
                    'matched_product_id' => $entry->matchedProductId,
                    'match_distance' => $entry->matchDistance,
                    'decision' => ScanItemDecision::Pending,
                ];
            }
            DB::transaction(fn () => $scan->items()->createMany($rows));
            $timings['save'] = $this->elapsed($step);

            // 8. Done. The codebase calls the terminal state Completed (spec: "ready").
            $timings['total'] = $this->elapsed($started);
            $scan->finished_at = now();
            $this->mark($scan, ScanStatus::Completed, 'Done', $timings);
        } catch (Throwable $e) {
            $this->mark($scan, ScanStatus::Failed, ($scan->stats ?? [])['progress'] ?? 'Failed', $timings, [
                'error' => substr($e->getMessage(), 0, 2000),
            ]);

            throw $e;
        }
    }

    /**
     * Safety net for timeouts, which kill the job without running the
     * catch block above. No-op when the catch already marked the scan.
     */
    public function failed(Throwable $exception): void
    {
        $scan = Scan::find($this->scanId);
        if ($scan === null || $scan->status === ScanStatus::Failed) {
            return;
        }
        $scan->status = ScanStatus::Failed;
        $scan->stats = array_merge($scan->stats ?? [], [
            'error' => substr($exception->getMessage(), 0, 2000),
        ]);
        $scan->save();
    }

    /**
     * @param  array<string, float>  $timings
     */
    private function mark(Scan $scan, ScanStatus $status, string $progress, array $timings, array $extra = []): void
    {
        $scan->status = $status;
        $scan->stats = array_merge($scan->stats ?? [], ['progress' => $progress, 'timings' => $timings], $extra);
        $scan->save();
    }

    private function elapsed(float $started): float
    {
        return round(microtime(true) - $started, 2);
    }

    private function sameSpotPairs(string $workerId): array
    {
        $path = "scans/{$workerId}/same_spot.json";
        if (! Storage::disk('shared')->exists($path)) {
            throw new \RuntimeException("same_spot.json not found for scan {$workerId}.");
        }

        return Storage::disk('shared')->json($path)['pairs'] ?? [];
    }
}
