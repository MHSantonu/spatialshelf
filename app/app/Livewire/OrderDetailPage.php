<?php

namespace App\Livewire;

use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Product;
use App\Support\Money;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.mobile')]
#[Title('Order')]
class OrderDetailPage extends Component
{
    public Order $order;

    /** @var array<int, bool> line ids ticked off while picking */
    public array $picked = [];

    public function mount(Order $order): void
    {
        $this->order = $order;
    }

    public function render(): View
    {
        $this->order->loadMissing(['customer', 'lines.product.images']);

        return view('livewire.order-detail-page', [
            'route' => $this->pickingRoute(),
            'unmatched' => $this->order->lines->filter(
                fn (OrderLine $line): bool => ! $line->product instanceof Product
            )->values(),
            'pickedCount' => count(array_filter($this->picked)),
        ]);
    }

    public static function money(float $value): string
    {
        return Money::format($value);
    }

    public static function customerName(Order $order): string
    {
        $customer = $order->customer;
        if (! $customer instanceof Customer) {
            return 'Demo';
        }

        return $customer->name ?? $customer->wa_id;
    }

    /**
     * Matched lines in shelf order: row by row, left to right.
     *
     * @return Collection<int, OrderLine>
     */
    public function pickingRoute(): Collection
    {
        return $this->order->lines
            ->filter(fn (OrderLine $line): bool => $line->product instanceof Product)
            ->sortBy([
                fn (OrderLine $line): int => $line->product->shelf_row ?? PHP_INT_MAX,
                fn (OrderLine $line): int => $line->product->shelf_order ?? PHP_INT_MAX,
                fn (OrderLine $line): int => $line->product->id,
            ])->values();
    }

    public static function shelfLabel(Product $product): string
    {
        if ($product->shelf_row === null) {
            return 'Unplaced';
        }

        return "Row {$product->shelf_row} · #".($product->shelf_order ?? '–');
    }

    public static function inputText(Order $order): ?string
    {
        // Larastan reads the JSON column as a string; casts() makes it an array at runtime.
        /** @var array<string, mixed>|null $context */
        $context = $order->context;
        $text = $context['input_text'] ?? null;
        if (is_string($text) && trim($text) !== '') {
            return trim($text);
        }
        $fallback = $order->lines
            ->map(fn (OrderLine $line): string => trim((string) $line->requested_text))
            ->filter()
            ->values()
            ->implode(', ');

        return $fallback === '' ? null : $fallback;
    }
}
