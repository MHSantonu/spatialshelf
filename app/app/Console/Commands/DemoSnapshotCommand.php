<?php

namespace App\Console\Commands;

use App\Models\Product;
use Illuminate\Console\Command;

class DemoSnapshotCommand extends Command
{
    protected $signature = 'demo:snapshot';

    protected $description = 'Store current product stock counts to storage/demo-stock.json for demo:reset --keep-catalog.';

    public function handle(): int
    {
        $stock = Product::query()->orderBy('id')->pluck('stock_qty', 'id')
            ->mapWithKeys(fn ($qty, $id) => [(string) $id => (int) $qty])
            ->all();
        $payload = [
            'taken_at' => now()->toIso8601String(),
            'products' => $stock,
        ];
        file_put_contents(
            DemoResetCommand::stockFilePath(),
            json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)."\n"
        );
        $this->info('Stock snapshot saved: '.count($stock).' products → storage/demo-stock.json.');

        return self::SUCCESS;
    }
}
