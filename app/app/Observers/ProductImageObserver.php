<?php

namespace App\Observers;

use App\Models\ProductImage;
use App\Services\Catalog\MemoryMatcher;

class ProductImageObserver
{
    public function saved(ProductImage $image): void
    {
        $this->bust($image);
    }

    public function deleted(ProductImage $image): void
    {
        $this->bust($image);
    }

    public function restored(ProductImage $image): void
    {
        $this->bust($image);
    }

    private function bust(ProductImage $image): void
    {
        $shopId = $image->product?->shop_id;
        if ($shopId !== null) {
            MemoryMatcher::bust((int) $shopId);
        }
    }
}
