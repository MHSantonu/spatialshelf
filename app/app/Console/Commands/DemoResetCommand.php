<?php

namespace App\Console\Commands;

use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\Scan;
use Illuminate\Console\Command;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;

class DemoResetCommand extends Command
{
    protected $signature = 'demo:reset {--keep-catalog : Keep products and product images, restoring stock from the snapshot}';

    protected $description = 'Wipe demo data (orders, conversations, customers, scans + files) before a recording take.';

    public static function stockFilePath(): string
    {
        return storage_path('demo-stock.json');
    }

    public function handle(): int
    {
        if (app()->isProduction()) {
            $this->error('demo:reset refuses to run in production.');

            return self::FAILURE;
        }
        $disk = Storage::disk('shared');

        $orderCount = Order::query()->count();
        foreach (Order::query()->pluck('raw_input_path') as $path) {
            $this->deleteSharedFile($disk, $path);
        }
        Order::query()->delete();

        $conversationCount = Conversation::query()->count();
        Conversation::query()->delete();

        $customerCount = Customer::query()->count();
        Customer::query()->delete();

        $scans = Scan::query()->get(['id', 'video_path']);
        foreach ($scans as $scan) {
            $disk->deleteDirectory("scans/{$scan->id}");
            $this->deleteSharedFile($disk, $scan->video_path);
        }
        $scanCount = $scans->count();
        Scan::query()->delete();

        if ($this->option('keep-catalog')) {
            $this->restoreStock();
            $this->info("demo:reset done: {$orderCount} orders, {$conversationCount} conversations, {$customerCount} customers, {$scanCount} scans deleted; catalog kept.");
        } else {
            $imageCount = ProductImage::query()->count();
            $productCount = Product::query()->count();
            Product::query()->delete();
            $this->info("demo:reset done: {$orderCount} orders, {$conversationCount} conversations, {$customerCount} customers, {$scanCount} scans, {$productCount} products and {$imageCount} images deleted.");
        }

        return self::SUCCESS;
    }

    private function restoreStock(): void
    {
        $file = self::stockFilePath();
        if (! is_file($file)) {
            $this->warn('No storage/demo-stock.json found — run demo:snapshot first. Stock left as-is.');

            return;
        }
        $data = json_decode((string) file_get_contents($file), true);
        $products = is_array($data['products'] ?? null) ? $data['products'] : [];
        $restored = 0;
        foreach ($products as $id => $qty) {
            if (! is_numeric($id) || ! is_numeric($qty)) {
                continue;
            }
            $restored += Product::query()->whereKey((int) $id)->update(['stock_qty' => max(0, (int) $qty)]);
        }
        $this->info("Stock restored for {$restored} products from storage/demo-stock.json.");
    }

    /**
     * Delete one shared-storage file referenced by a deleted row. Absolute
     * paths and anything with .. are left alone: only shared-relative files
     * belong to us.
     */
    private function deleteSharedFile(FilesystemAdapter $disk, mixed $path): void
    {
        if (! is_string($path) || trim($path) === '' || str_contains($path, '..')) {
            return;
        }
        if (str_starts_with($path, '/') || (bool) preg_match('/^[A-Za-z]:[\\\\\\/]/', $path)) {
            return;
        }
        $disk->delete($path);
    }
}
