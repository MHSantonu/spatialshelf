<?php

namespace App\Services\Orders;

use App\Models\Product;
use App\Support\Money;

final class CatalogSnapshot
{
    /** @var array<int, array{table: string, ids: list<string>}> */
    private static array $cache = [];

    public function __construct(
        private readonly int $shopId,
    ) {}

    public function table(): string
    {
        return $this->snapshot()['table'];
    }

    /** @return list<string> */
    public function productIds(): array
    {
        return $this->snapshot()['ids'];
    }

    public static function bust(int $shopId): void
    {
        unset(self::$cache[$shopId]);
    }

    public static function flush(): void
    {
        self::$cache = [];
    }

    /** @return array{table: string, ids: list<string>} */
    private function snapshot(): array
    {
        return self::$cache[$this->shopId] ??= $this->build();
    }

    /** @return array{table: string, ids: list<string>} */
    private function build(): array
    {
        $products = Product::query()
            ->select(['id', 'name', 'size_value', 'size_unit', 'price', 'stock_qty', 'aliases'])
            ->where('shop_id', $this->shopId)
            ->where('active', true)
            ->orderBy('name')
            ->orderBy('id')
            ->get();

        $lines = [];
        $ids = [];
        foreach ($products as $product) {
            /** @var Product $product */
            $ids[] = (string) $product->id;
            $lines[] = implode(' | ', [
                (string) $product->id,
                (string) $product->name,
                self::sizeFor($product),
                self::money((float) $product->price),
                'in stock: '.((int) $product->stock_qty),
                'aka: '.self::aliasesFor($product),
            ]);
        }

        return ['table' => implode("\n", $lines), 'ids' => $ids];
    }

    private static function sizeFor(Product $product): string
    {
        if (is_numeric($product->size_value) && (float) $product->size_value > 0) {
            $value = rtrim(rtrim(sprintf('%.2f', (float) $product->size_value), '0'), '.');
            $unit = trim((string) $product->size_unit);

            return $unit !== '' ? $value.' '.$unit : $value;
        }

        return 'size unknown';
    }

    private static function money(float $value): string
    {
        return Money::format($value);
    }

    private static function aliasesFor(Product $product): string
    {
        $aliases = $product->aliases ?? [];

        return implode(', ', array_map(strval(...), is_array($aliases) ? $aliases : []));
    }
}
