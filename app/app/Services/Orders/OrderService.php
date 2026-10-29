<?php

namespace App\Services\Orders;

use App\Enums\OrderStatus;
use App\Exceptions\InsufficientStock;
use App\Exceptions\OrderStateException;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Support\Facades\DB;

/**
 * Confirms and cancels draft orders.
 *
 * Confirm runs in one transaction: it locks the order row plus every
 * product on the order (id order, so concurrent confirms serialize
 * instead of deadlocking), re-checks stock against the locked rows, and
 * only then decrements. A second confirm of the same order therefore
 * fails instead of overselling. It also feeds the text learning loop:
 * each matched line's requested_text becomes a product alias unless it
 * just repeats the product name.
 */
final class OrderService
{
    public function confirm(Order $order): Order
    {
        return DB::transaction(function () use ($order): Order {
            $locked = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            // Larastan reads the string column, but casts() makes this an enum at runtime.
            /** @var OrderStatus $status */
            $status = $locked->status;
            if ($status !== OrderStatus::Draft) {
                throw new OrderStateException(
                    "Only draft orders can be confirmed (order {$locked->id} is {$status->value})."
                );
            }

            $lines = $locked->lines()->orderBy('id')->get();
            $productIds = [];
            foreach ($lines as $line) {
                if ($line->product_id !== null) {
                    $productIds[] = (int) $line->product_id;
                }
            }
            $products = Product::query()
                ->whereIn('id', array_values(array_unique($productIds)))
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $short = [];
            foreach ($lines as $line) {
                if ($line->product_id === null) {
                    continue;
                }
                $product = $products->get($line->product_id);
                $available = $product instanceof Product && $product->active ? (int) $product->stock_qty : 0;
                if ($available < (int) $line->qty) {
                    $short[] = [
                        'product_id' => (int) $line->product_id,
                        'name' => $product instanceof Product
                            ? (string) $product->name
                            : (string) ($line->requested_text ?? "Product #{$line->product_id}"),
                        'wanted' => (int) $line->qty,
                        'available' => $available,
                    ];
                }
            }
            if ($short !== []) {
                throw new InsufficientStock($short);
            }

            foreach ($lines as $line) {
                if ($line->product_id === null) {
                    continue;
                }
                $product = $products->get($line->product_id);
                if (! $product instanceof Product || ! $product->active) {
                    continue;
                }
                // Sufficiency was just proven above; max() only teaches the checker.
                $product->stock_qty = max(0, (int) $product->stock_qty - (int) $line->qty);
                // setAttribute (not magic assignment): Larastan types the array-cast column as string.
                $product->setAttribute('aliases', $this->learnAlias($product, (string) ($line->requested_text ?? '')));
                $product->save();
            }

            $locked->update(['status' => OrderStatus::Confirmed, 'confirmed_at' => now()]);

            return $locked->refresh();
        });
    }

    public function pack(Order $order): Order
    {
        return DB::transaction(function () use ($order): Order {
            $locked = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            // Larastan reads the string column, but casts() makes this an enum at runtime.
            /** @var OrderStatus $status */
            $status = $locked->status;
            if ($status !== OrderStatus::Confirmed) {
                throw new OrderStateException(
                    "Only confirmed orders can be packed (order {$locked->id} is {$status->value})."
                );
            }
            $locked->update(['status' => OrderStatus::Packed]);

            return $locked->refresh();
        });
    }

    public function cancel(Order $order): Order
    {
        $order->refresh();
        // Larastan reads the string column, but casts() makes this an enum at runtime.
        /** @var OrderStatus $status */
        $status = $order->status;
        if ($status !== OrderStatus::Draft) {
            throw new OrderStateException(
                "Only draft orders can be cancelled (order {$order->id} is {$status->value})."
            );
        }
        $order->update(['status' => OrderStatus::Cancelled]);

        return $order->refresh();
    }

    /**
     * Fold the customer's own words into the product's aliases: skip blanks
     * and repeats of the product name (case-insensitive), dedupe, keep the
     * newest 10.
     *
     * @return list<string>
     */
    private function learnAlias(Product $product, string $text): array
    {
        $aliases = [];
        foreach ((array) ($product->aliases ?? []) as $alias) {
            $aliases[] = (string) $alias;
        }
        $text = trim($text);
        if ($text === '') {
            return $aliases;
        }
        $known = array_map(
            fn (string $alias): string => mb_strtolower($alias, 'UTF-8'),
            [...$aliases, (string) $product->name]
        );
        if (in_array(mb_strtolower($text, 'UTF-8'), $known, true)) {
            return $aliases;
        }

        return array_slice([...$aliases, $text], -10);
    }
}
