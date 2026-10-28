<?php

namespace App\Models;

use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['shop_id', 'name', 'brand', 'size_value', 'size_unit', 'category', 'price', 'stock_qty', 'stock_is_estimate', 'shelf_row', 'shelf_order', 'aliases', 'active'])]
class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'size_value' => 'decimal:2',
            'price' => 'decimal:2',
            'stock_qty' => 'integer',
            'stock_is_estimate' => 'boolean',
            'shelf_row' => 'integer',
            'shelf_order' => 'integer',
            'aliases' => 'array',
            'active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Shop, $this>
     */
    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    /**
     * @return HasMany<ProductImage, $this>
     */
    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class);
    }

    /**
     * @return HasMany<ScanItem, $this>
     */
    public function scanItems(): HasMany
    {
        return $this->hasMany(ScanItem::class, 'matched_product_id');
    }

    /**
     * @return HasMany<OrderLine, $this>
     */
    public function orderLines(): HasMany
    {
        return $this->hasMany(OrderLine::class);
    }
}
