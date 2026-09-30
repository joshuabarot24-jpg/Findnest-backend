<?php

namespace Database\Factories;

use App\Models\LostItemReport;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\LostItemReport>
 */
class LostItemReportFactory extends Factory
{
    protected $model = LostItemReport::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory()->student(),
            'item_name' => fake()->randomElement(['Blue Backpack', 'Silver Laptop', 'Black Hydroflask', 'Scientific Calculator', 'ID Card']),
            'category' => fake()->randomElement(['Electronics', 'Bags', 'Personal Belongings', 'Accessories', 'Documents']),
            'description' => fake()->sentence(8),
            'ai_description' => null,
            'location_lost' => fake()->randomElement(['Main Library', 'College Canteen', 'Building B Room 204', 'Gymnasium']),
            'date_lost' => now('Asia/Manila')->format('Y-m-d'),
            'approx_time' => '10:00 AM',
            'primary_color' => fake()->safeColorName(),
            'brand_model' => fake()->randomElement(['Apple', 'Casio', 'Nike', 'Samsung', 'Hydro Flask']),
            'photo_url' => null,
            'photo_urls' => null,
            'status' => 'searching',
        ];
    }

    public function resolved(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'resolved',
        ]);
    }

    public function closed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'closed',
        ]);
    }
}
