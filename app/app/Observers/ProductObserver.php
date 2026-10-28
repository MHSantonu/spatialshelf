<?php

namespace App\Observers;

use App\Models\Product;
use App\Services\Orders\CatalogSnapshot;

class ProductObserver
{
    public function saved(Product $product): void
    {
        $this->bust($product);
    }

    public function deleted(Product $product): void
    {
        $this->bust($product);
    }

    private function bust(Product $product): void
    {
        CatalogSnapshot::bust((int) $product->shop_id);

        $original = $product->getOriginal('shop_id');
        if ($original !== null && (int) $original !== (int) $product->shop_id) {
            CatalogSnapshot::bust((int) $original);
        }
    }
}
