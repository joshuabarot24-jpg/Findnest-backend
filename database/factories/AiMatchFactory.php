<?php

namespace Database\Factories;

use App\Models\AiMatch;
use App\Models\FoundItemRecord;
use App\Models\LostItemReport;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\AiMatch>
 */
class AiMatchFactory extends Factory
{
    protected $model = AiMatch::class;

    public function definition(): array
    {
        return [
            'report_id' => LostItemReport::factory(),
            'found_id' => FoundItemRecord::factory(),
            'confidence_score' => fake()->numberBetween(75, 95),
            'attributes' => [
                'description_score' => 85,
                'photo_score' => 80,
                'category_score' => 100,
                'date_score' => 90,
                'location_score' => 85,
            ],
            'match_status' => 'pending',
            'matched_at' => now(),
        ];
    }

    public function confirmed(): static
    {
        return $this->state(fn (array $attributes) => [
            'match_status' => 'confirmed',
        ]);
    }

    public function rejected(): static
    {
        return $this->state(fn (array $attributes) => [
            'match_status' => 'rejected',
        ]);
    }
}
