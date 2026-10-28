<?php

namespace App\Livewire;

use App\Actions\ConfirmScanItem;
use App\Enums\ScanItemDecision;
use App\Models\Scan;
use App\Models\ScanItem;
use App\Support\Vec;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.mobile')]
#[Title('Review products')]
class ScanReviewPage extends Component
{
    public const CATEGORIES = [
        'snacks', 'biscuits', 'oil', 'drinks', 'spices', 'rice_dal',
        'soap_detergent', 'personal_care', 'sauces_condiments', 'other',
    ];

    public const SIZE_UNITS = ['g', 'kg', 'ml', 'l', 'pcs', 'unknown'];

    public Scan $scan;

    public ?int $currentItemId = null;

    public int $total = 0;

    public string $price = '';

    public bool $editing = false;

    /** @var array{name: string, size_value: mixed, size_unit: string, category: string, qty: mixed} */
    public array $form = [];

    public bool $picking = false;

    public function mount(Scan $scan): void
    {
        $this->scan = $scan;
        $this->total = $this->pendingQuery()->count();
        $this->advance();
    }

    public function confirm(): void
    {
        $this->validate(['price' => ['required', 'numeric', 'min:0.01']]);
        $item = $this->current();
        if ($item === null) {
            return;
        }

        (new ConfirmScanItem)->execute($item, (float) $this->price, $this->form);

        $this->advance();
    }

    public function reject(): void
    {
        $item = $this->current();
        if ($item === null) {
            return;
        }
        $item->decision = ScanItemDecision::Rejected;
        $item->save();

        $this->advance();
    }

    public function rejectUnclear(): void
    {
        foreach ($this->pendingItems() as $item) {
            if (self::isUnclear($item)) {
                $item->decision = ScanItemDecision::Rejected;
                $item->save();
            }
        }

        $this->advance();
    }

    public function startEdit(): void
    {
        if ($this->form === []) {
            $item = $this->current();
            if ($item === null) {
                return;
            }
            $extracted = $item->extracted ?? [];
            $this->form = [
                'name' => $extracted['name'] ?? '',
                'size_value' => $extracted['size_value'] ?? '',
                'size_unit' => $extracted['size_unit'] ?? 'unknown',
                'category' => $extracted['category'] ?? 'other',
                'qty' => $item->est_qty ?? 0,
            ];
        }
        $this->editing = true;
    }

    public function cancelEdit(): void
    {
        $this->editing = false;
        $this->form = [];
    }

    public function saveEdit(): void
    {
        if (($this->form['size_value'] ?? null) === '') {
            $this->form['size_value'] = null;
        }
        $this->validate([
            'form.name' => ['required', 'string', 'max:255'],
            'form.size_value' => ['nullable', 'numeric', 'min:0'],
            'form.size_unit' => ['required', 'in:'.implode(',', self::SIZE_UNITS)],
            'form.category' => ['required', 'in:'.implode(',', self::CATEGORIES)],
            'form.qty' => ['required', 'integer', 'min:0'],
        ]);

        // Staged only: confirm() applies the form to the item and product.
        $this->editing = false;
    }

    public function startPick(): void
    {
        $this->picking = true;
    }

    public function cancelPick(): void
    {
        $this->picking = false;
    }

    public function mergeWith(int $otherId): void
    {
        $current = $this->current();
        $other = $this->pendingItems()->firstWhere('id', $otherId);
        if ($current === null || $other === null || $other->id === $current->id) {
            return;
        }

        DB::transaction(function () use ($current, $other): void {
            if (self::mergeScore($other) > self::mergeScore($current)) {
                $current->extracted = $other->extracted;
                $current->matched_product_id = $other->matched_product_id;
                $current->match_distance = $other->match_distance;
            }
            if (self::largerCrop($other, $current)->is($other)) {
                $current->crop_path = $other->crop_path;
            }
            $current->facings = ($current->facings ?? 0) + ($other->facings ?? 0);
            $current->est_qty = ($current->est_qty ?? 0) + ($other->est_qty ?? 0);
            $current->save();

            $other->decision = ScanItemDecision::Merged;
            $other->save();
        });

        $this->advance();
    }

    public function render()
    {
        $current = $this->current();
        $pending = $this->pendingItems();
        $remaining = $pending->count();
        $extracted = $current?->extracted ?? [];

        return view('livewire.scan-review-page', [
            'current' => $current,
            'shown' => array_merge([
                'name' => $extracted['name'] ?? 'Unnamed product',
                'size_value' => $extracted['size_value'] ?? 0,
                'size_unit' => $extracted['size_unit'] ?? 'unknown',
                'category' => $extracted['category'] ?? 'other',
                'qty' => $current?->est_qty ?? 0,
            ], $this->form),
            'position' => $this->total > 0 ? min($this->total - $remaining + 1, $this->total) : 0,
            'others' => $current === null ? [] : $pending
                ->reject(fn (ScanItem $item) => $item->id === $current->id)
                ->sortBy([
                    fn (ScanItem $item) => $item->shelf_row === $current->shelf_row ? 0 : 1,
                    fn (ScanItem $item) => [$item->shelf_row, $item->shelf_order],
                ])->values(),
            'nearest' => $current !== null && self::isUnclear($current)
                ? $this->nearestByEmbedding($current, $pending)
                : null,
            'unclearRemaining' => $pending->filter(fn (ScanItem $item) => self::isUnclear($item))->count(),
        ]);
    }

    public static function isUnclear(ScanItem $item): bool
    {
        return str_contains((string) (($item->extracted ?? [])['name'] ?? ''), '(brand unclear)');
    }

    public static function pricePrefill(ScanItem $item): string
    {
        $matched = $item->matchedProduct;
        if ($matched !== null && (float) $matched->price > 0) {
            $price = (float) $matched->price;

            return $price == (int) $price ? (string) (int) $price : (string) $matched->price;
        }
        $digits = (string) preg_replace('/[^\d.]/', '', (string) (($item->extracted ?? [])['mrp_text'] ?? ''));

        return $digits !== '' && $digits !== '.' ? $digits : '';
    }

    private function advance(): void
    {
        $current = $this->pendingQuery()->first();
        $this->currentItemId = $current?->id;
        $this->price = $current === null ? '' : self::pricePrefill($current);
        $this->editing = false;
        $this->form = [];
        $this->picking = false;
    }

    private function current(): ?ScanItem
    {
        return $this->currentItemId === null
            ? null
            : ScanItem::query()->find($this->currentItemId);
    }

    private function pendingQuery()
    {
        return $this->scan->items()
            ->where('decision', ScanItemDecision::Pending)
            ->orderBy('shelf_row')
            ->orderBy('shelf_order');
    }

    /** @return \Illuminate\Support\Collection<int, ScanItem> */
    private function pendingItems()
    {
        return $this->pendingQuery()->with('matchedProduct')->get();
    }

    /**
     * Merge score: matched beats unmatched, clear beats unclear, then confidence.
     * PHP compares the arrays lexicographically.
     *
     * @return array{bool, bool, float}
     */
    private static function mergeScore(ScanItem $item): array
    {
        return [
            $item->matched_product_id !== null,
            ! self::isUnclear($item),
            (float) (($item->extracted ?? [])['confidence'] ?? 0),
        ];
    }

    private static function largerCrop(ScanItem $a, ScanItem $b): ScanItem
    {
        return self::cropArea($a) >= self::cropArea($b) ? $a : $b;
    }

    private static function cropArea(ScanItem $item): float
    {
        $disk = Storage::disk('shared');
        $dims = @getimagesize($disk->path($item->crop_path));
        if (is_array($dims)) {
            return (float) $dims[0] * $dims[1];
        }

        return (float) ($disk->size($item->crop_path) ?: 0);
    }

    /** @param \Illuminate\Support\Collection<int, ScanItem> $pending */
    private function nearestByEmbedding(ScanItem $current, $pending): ?ScanItem
    {
        if (empty($current->embedding) || ! is_array($current->embedding)) {
            return null;
        }
        $best = null;
        $bestSim = null;
        foreach ($pending as $item) {
            if ($item->id === $current->id || empty($item->embedding) || ! is_array($item->embedding)) {
                continue;
            }
            try {
                $sim = Vec::cosineSimilarity($current->embedding, $item->embedding);
            } catch (\InvalidArgumentException) {
                continue;
            }
            if ($bestSim === null || $sim > $bestSim) {
                $bestSim = $sim;
                $best = $item;
            }
        }

        return $best;
    }
}
