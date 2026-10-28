<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\Shop;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
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
            'name' => fake()->words(2, true),
            'brand' => fake()->company(),
            'size_value' => fake()->randomElement([50, 100, 200, 250, 400, 500, 1000]),
            'size_unit' => fake()->randomElement(['g', 'kg', 'ml', 'l', 'pcs']),
            'category' => fake()->randomElement(['snacks', 'biscuits', 'oil', 'drinks', 'spices', 'rice_dal', 'soap_detergent', 'personal_care', 'other']),
            'price' => fake()->randomFloat(2, 10, 500),
            'stock_qty' => fake()->numberBetween(0, 48),
            'stock_is_estimate' => true,
            'shelf_row' => fake()->numberBetween(1, 4),
            'shelf_order' => fake()->numberBetween(1, 20),
            'aliases' => [],
            'active' => true,
        ];
    }
}
