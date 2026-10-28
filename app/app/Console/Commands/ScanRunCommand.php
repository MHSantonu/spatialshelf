<?php

namespace App\Console\Commands;

use App\Jobs\ProcessScan;
use App\Models\Scan;
use App\Models\Shop;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ScanRunCommand extends Command
{
    protected $signature = 'scan:run {path : Video file, absolute or relative to the repo root}';

    protected $description = 'Copy a video into shared uploads, scan it synchronously, and print the results.';

    public function handle(): int
    {
        $absolute = $this->resolveVideoPath((string) $this->argument('path'));
        if ($absolute === null) {
            $this->error("Video not found: {$this->argument('path')}");

            return self::FAILURE;
        }
        $shop = Shop::where('name', 'Mahadi Store, Tangail')->first();
        if ($shop === null) {
            $this->error('Demo shop not found. Run: php artisan db:seed');

            return self::FAILURE;
        }

        try {
            $scan = Scan::create([
                'shop_id' => $shop->id,
                'video_path' => $this->storeUpload($absolute),
            ]);
            ProcessScan::dispatchSync($scan->id);
        } catch (Throwable $e) {
            $this->error("Scan failed: {$e->getMessage()}");

            return self::FAILURE;
        }

        $scan->refresh();
        $this->info("Scan {$scan->id}: {$scan->status->value} ({$scan->items()->count()} items)");
        $this->table(
            ['Row', 'Order', 'Name', 'Size', 'Facings', 'Est', 'Known?', 'Conf'],
            $scan->items()->orderBy('shelf_row')->orderBy('shelf_order')->get()
                ->map(fn ($item) => [
                    $item->shelf_row,
                    $item->shelf_order,
                    ($item->extracted ?? [])['name'] ?? '?',
                    $this->formatSize($item->extracted ?? []),
                    $item->facings,
                    $item->est_qty,
                    $item->matched_product_id !== null ? 'yes' : 'no',
                    isset(($item->extracted ?? [])['confidence'])
                        ? number_format((float) ($item->extracted ?? [])['confidence'], 2)
                        : '?',
                ])->all()
        );
        $timings = $scan->stats['timings'] ?? [];
        $this->table(
            ['Step', 'Seconds'],
            array_map(fn ($step, $seconds) => [$step, $seconds], array_keys($timings), $timings)
        );

        return self::SUCCESS;
    }

    private function resolveVideoPath(string $path): ?string
    {
        if (is_file($path)) {
            return realpath($path) ?: $path;
        }
        $rooted = dirname(base_path()).DIRECTORY_SEPARATOR.$path;
        if (is_file($rooted)) {
            return realpath($rooted) ?: $rooted;
        }

        return null;
    }

    private function storeUpload(string $absolute): string
    {
        $disk = Storage::disk('shared');
        $disk->makeDirectory('uploads');
        $name = basename($absolute);
        $stem = pathinfo($name, PATHINFO_FILENAME);
        $ext = pathinfo($name, PATHINFO_EXTENSION);
        $candidate = $name;
        for ($i = 2; $disk->exists("uploads/{$candidate}"); $i++) {
            $candidate = $ext !== '' ? "{$stem}_{$i}.{$ext}" : "{$stem}_{$i}";
        }
        if (! @copy($absolute, $disk->path("uploads/{$candidate}"))) {
            throw new \RuntimeException("Could not copy {$absolute} into shared uploads/.");
        }

        return "uploads/{$candidate}";
    }

    /**
     * Ported from spikes/read_crops.py format_size.
     */
    private function formatSize(array $extracted): string
    {
        $value = $extracted['size_value'] ?? null;
        $unit = $extracted['size_unit'] ?? 'unknown';
        if (! is_numeric($value)) {
            return "? {$unit}";
        }

        return sprintf('%g', (float) $value)." {$unit}";
    }
}
