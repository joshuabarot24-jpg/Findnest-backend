<?php

namespace Database\Factories;

use App\Models\FoundItemRecord;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\FoundItemRecord>
 */
class FoundItemRecordFactory extends Factory
{
    protected $model = FoundItemRecord::class;

    public function definition(): array
    {
        return [
            'admin_id' => User::factory()->admin(),
            'item_name' => fake()->randomElement(['Blue Backpack', 'Silver Laptop', 'Black Hydroflask', 'Scientific Calculator', 'ID Card']),
            'category' => fake()->randomElement(['Electronics', 'Bags', 'Personal Belongings', 'Accessories', 'Documents']),
            'description' => fake()->sentence(8),
            'ai_description' => null,
            'location_found' => fake()->randomElement(['Main Library', 'College Canteen', 'Building B Room 204', 'Gymnasium']),
            'date_found' => now('Asia/Manila')->format('Y-m-d'),
            'approx_time' => '11:00 AM',
            'primary_color' => fake()->safeColorName(),
            'brand_model' => fake()->randomElement(['Apple', 'Casio', 'Nike', 'Samsung', 'Hydro Flask']),
            'photo_url' => null,
            'photo_urls' => null,
            'storage_location' => 'Guidance Office Cabinet 1',
            'status' => 'unclaimed',
            'receipt_confirmed' => true,
            'receipt_confirmed_at' => now(),
        ];
    }

    public function unclaimed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'unclaimed',
        ]);
    }

    public function claimed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'claimed',
        ]);
    }

    public function disposed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'disposed',
            'disposed_at' => now(),
            'disposal_notes' => 'Disposed per institutional policy.',
        ]);
    }

    public function pendingReceipt(): static
    {
        return $this->state(fn (array $attributes) => [
            'receipt_confirmed' => false,
            'receipt_confirmed_at' => null,
            'surrender_deadline' => now()->addDays(2),
        ]);
    }
}
