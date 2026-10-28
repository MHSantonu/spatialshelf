<?php

namespace Database\Factories;

use App\Enums\OrderStatus;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\Shop;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Order>
 */
class OrderFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'shop_id' => Shop::factory(),
            'customer_id' => Customer::factory(),
            'status' => OrderStatus::Draft,
            'source_type' => 'text',
            'raw_input_path' => null,
            'transcript' => null,
            'context' => null,
            'total' => 0,
            'confirmed_at' => null,
        ];
    }

    /**
     * Create the order with priced lines for products in the same shop,
     * and roll the line totals up into the order total like PHP does
     * when the model prices an order (never the model).
     */
    public function configure(): static
    {
        return $this->afterCreating(function (Order $order): void {
            $total = 0;

            foreach (Product::factory()->count(2)->create(['shop_id' => $order->shop_id]) as $product) {
                $qty = fake()->numberBetween(1, 3);
                $lineTotal = $qty * (float) $product->price;

                $order->lines()->create([
                    'product_id' => $product->id,
                    'requested_text' => $product->name,
                    'qty' => $qty,
                    'unit_price' => $product->price,
                    'line_total' => $lineTotal,
                    'match_confidence' => 1.0,
                ]);

                $total += $lineTotal;
            }

            $order->update(['total' => $total]);
        });
    }
}
