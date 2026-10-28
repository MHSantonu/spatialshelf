<?php

namespace App\Livewire;

use App\Models\Product;
use App\Models\Shop;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.mobile')]
#[Title('Stock')]
class StockPage extends Component
{
    public string $search = '';

    public ?int $editingId = null;

    public string $editPrice = '';

    public string $editQty = '';

    public function startEdit(int $id): void
    {
        $product = $this->shopProducts()->findOrFail($id);
        $this->editingId = $product->id;
        $this->editPrice = (string) $product->price;
        $this->editQty = (string) $product->stock_qty;
    }

    public function cancelEdit(): void
    {
        $this->editingId = null;
        $this->editPrice = '';
        $this->editQty = '';
    }

    public function saveEdit(): void
    {
        $product = $this->shopProducts()->findOrFail($this->editingId);
        $validated = $this->validate([
            'editPrice' => ['required', 'numeric', 'min:0'],
            'editQty' => ['required', 'integer', 'min:0'],
        ]);

        $product->price = $validated['editPrice'];
        if ((int) $validated['editQty'] !== (int) $product->stock_qty) {
            $product->stock_qty = max(0, (int) $validated['editQty']);
            $product->stock_is_estimate = false;
        }
        $product->save();

        $this->cancelEdit();
    }

    public function render(): View
    {
        $products = $this->shopProducts()
            ->when(trim($this->search) !== '', function ($query): void {
                $term = '%'.trim($this->search).'%';
                $query->where(fn ($q) => $q->where('name', 'like', $term)->orWhere('brand', 'like', $term));
            })
            ->orderByRaw('shelf_row IS NULL, shelf_row, shelf_order')
            ->with('images')
            ->get();

        $value = $products->sum(fn (Product $product) => (float) $product->price * $product->stock_qty);

        return view('livewire.stock-page', [
            'groups' => $products->groupBy(fn (Product $product) => $product->shelf_row ?? 0),
            'totalProducts' => $products->count(),
            'totalValue' => $value,
        ]);
    }

    public static function money(float $value): string
    {
        return Money::format($value);
    }

    public static function sizeFor(Product $product): string
    {
        if (is_numeric($product->size_value) && (float) $product->size_value > 0) {
            return rtrim(rtrim(sprintf('%.2f', (float) $product->size_value), '0'), '.').' '.$product->size_unit;
        }

        return 'size unknown';
    }

    /** @return Builder<Product> */
    private function shopProducts(): Builder
    {
        return Product::query()
            ->where('shop_id', Shop::query()->value('id'))
            ->where('active', true);
    }
}
