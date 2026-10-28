<?php

namespace Database\Factories;

use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrderLine>
 */
class OrderLineFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'order_id' => Order::factory(),
            'product_id' => Product::factory(),
            'requested_text' => fake()->words(2, true),
            'qty' => 1,
            'unit_price' => 50.00,
            'line_total' => 50.00,
            'match_confidence' => 1.0,
            'status' => 'matched',
            'note' => null,
            'alternative_ids' => [],
        ];
    }
}
