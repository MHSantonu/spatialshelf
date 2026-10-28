<?php

namespace App\Models;

use App\Enums\ScanStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

#[Fillable(['shop_id', 'video_path', 'status', 'frames_processed', 'started_at', 'finished_at', 'stats'])]
class Scan extends Model
{
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ScanStatus::class,
            'frames_processed' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'stats' => 'array',
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
     * @return HasMany<ScanItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(ScanItem::class);
    }

    /**
     * Phrase slug of the scan's non-empty video-mode overlay file, if any.
     */
    public function overlaySlug(): ?string
    {
        $disk = Storage::disk('shared');
        if (! $disk->directoryExists("scans/{$this->id}/sam")) {
            return null;
        }
        $files = $disk->files("scans/{$this->id}/sam");
        sort($files);
        foreach ($files as $file) {
            $base = basename($file);
            if (str_starts_with($base, 'overlay_') && str_ends_with($base, '.txt')
                && $disk->size($file) > 0) {
                return substr($base, strlen('overlay_'), -strlen('.txt'));
            }
        }

        return null;
    }

    /**
     * @return HasMany<ProductImage, $this>
     */
    public function sourceImages(): HasMany
    {
        return $this->hasMany(ProductImage::class, 'source_scan_id');
    }
}
