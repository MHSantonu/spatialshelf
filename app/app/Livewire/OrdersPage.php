<?php

namespace App\Livewire;

use App\Enums\OrderStatus;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Shop;
use App\Support\Money;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.mobile')]
#[Title('Orders')]
class OrdersPage extends Component
{
    public function render(): View
    {
        $shopId = Shop::query()->value('id');
        $orders = Order::query()
            ->where('shop_id', $shopId)
            ->with(['customer', 'lines'])
            ->latest('id')
            ->get();
        $newCount = Order::query()
            ->where('shop_id', $shopId)
            ->where('status', OrderStatus::Confirmed)
            ->count();

        return view('livewire.orders-page', [
            'orders' => $orders,
            'newCount' => $newCount,
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

    public static function itemCount(Order $order): int
    {
        return (int) $order->lines->sum('qty');
    }
}
