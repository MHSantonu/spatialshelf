<?php

namespace App\Actions;

use App\Enums\ScanItemDecision;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ScanItem;
use Illuminate\Support\Facades\DB;

class ConfirmScanItem
{
    /**
     * Confirm a reviewed item: apply the merchant's edited fields, write the
     * product (update the match or create one), always add a memory image.
     *
     * @param  array{name?: string, size_value?: mixed, size_unit?: string, category?: string, qty?: mixed}  $fields
     * @param  bool  $updateCounts  matched items only: write the scan's est_qty
     *                              into stock; when false the count is left alone
     */
    public function execute(ScanItem $item, float $price, array $fields = [], bool $updateCounts = true): Product
    {
        return DB::transaction(function () use ($item, $price, $fields, $updateCounts): Product {
            $originalEst = (int) ($item->est_qty ?? 0);
            $extracted = $item->extracted ?? [];
            foreach (['name', 'size_value', 'size_unit', 'category'] as $key) {
                if (array_key_exists($key, $fields)) {
                    $extracted[$key] = $fields[$key];
                }
            }
            if (($extracted['size_value'] ?? null) === '' || ($extracted['size_value'] ?? null) === null) {
                $extracted['size_value'] = 0;
            }
            $qty = (int) ($fields['qty'] ?? $originalEst);
            $item->extracted = $extracted;
            $item->est_qty = $qty;

            if ($item->matched_product_id !== null) {
                $product = Product::findOrFail($item->matched_product_id);
                $updates = [
                    'shelf_row' => $item->shelf_row,
                    'shelf_order' => $item->shelf_order,
                ];
                if ($updateCounts) {
                    $updates['stock_qty'] = $qty;
                    $updates['stock_is_estimate'] = $qty === $originalEst;
                }
                if ((float) $product->price !== $price) {
                    $updates['price'] = $price;
                }
                $product->update($updates);
            } else {
                $product = Product::create([
                    'shop_id' => $item->scan->shop_id,
                    'name' => $extracted['name'] ?? 'Unnamed product',
                    'brand' => $extracted['brand'] ?? null,
                    'size_value' => $extracted['size_value'] ?? 0,
                    'size_unit' => $extracted['size_unit'] ?? 'unknown',
                    'category' => $extracted['category'] ?? 'other',
                    'price' => $price,
                    'stock_qty' => $qty,
                    'stock_is_estimate' => $qty === $originalEst,
                    'shelf_row' => $item->shelf_row,
                    'shelf_order' => $item->shelf_order,
                    'active' => true,
                ]);
                $item->matched_product_id = $product->id;
            }

            ProductImage::create([
                'product_id' => $product->id,
                'crop_path' => $item->crop_path,
                'embedding' => $item->embedding,
                'source_scan_id' => $item->scan_id,
            ]);

            $item->decision = ScanItemDecision::Confirmed;
            $item->save();

            return $product;
        });
    }
}
