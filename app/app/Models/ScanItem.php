<?php

namespace App\Models;

use App\Enums\ScanItemDecision;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['scan_id', 'crop_path', 'embedding', 'facings', 'est_qty', 'shelf_row', 'shelf_order', 'extracted', 'matched_product_id', 'match_distance', 'decision'])]
class ScanItem extends Model
{
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'embedding' => 'array',
            'facings' => 'integer',
            'est_qty' => 'integer',
            'shelf_row' => 'integer',
            'shelf_order' => 'integer',
            'extracted' => 'array',
            'match_distance' => 'float',
            'decision' => ScanItemDecision::class,
        ];
    }

    /**
     * @return BelongsTo<Scan, $this>
     */
    public function scan(): BelongsTo
    {
        return $this->belongsTo(Scan::class);
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function matchedProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'matched_product_id');
    }
}
