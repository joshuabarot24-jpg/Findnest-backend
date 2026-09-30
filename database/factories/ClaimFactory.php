<?php

namespace Database\Factories;

use App\Models\AiMatch;
use App\Models\Claim;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Claim>
 */
class ClaimFactory extends Factory
{
    protected $model = Claim::class;

    public function definition(): array
    {
        return [
            'match_id' => AiMatch::factory(),
            'student_id' => User::factory()->student(),
            'admin_id' => null,
            'proof_description' => fake()->paragraph(),
            'proof_photo_url' => null,
            'proof_photo_urls' => null,
            'photo_similarity_score' => null,
            'claim_status' => 'pending',
            'admin_notes' => null,
            'claimed_at' => now(),
            'pickup_deadline' => null,
            'collected_at' => null,
            'reminder_sent' => false,
            'verification_skipped' => false,
        ];
    }

    public function approved(?User $admin = null): static
    {
        return $this->state(fn (array $attributes) => [
            'claim_status' => 'approved',
            'admin_id' => $admin?->id ?? User::factory()->admin(),
            'pickup_deadline' => now()->addDays(7)->format('Y-m-d'),
        ]);
    }

    public function rejected(?User $admin = null): static
    {
        return $this->state(fn (array $attributes) => [
            'claim_status' => 'rejected',
            'admin_id' => $admin?->id ?? User::factory()->admin(),
            'admin_notes' => 'Proof provided did not match the item.',
        ]);
    }

    public function collected(): static
    {
        return $this->state(fn (array $attributes) => [
            'claim_status' => 'collected',
            'collected_at' => now(),
        ]);
    }

    public function appealed(): static
    {
        return $this->state(fn (array $attributes) => [
            'claim_status' => 'rejected',
            'appeal_status' => 'pending',
            'appeal_message' => 'I have attached additional evidence regarding item ownership.',
            'appeal_submitted_at' => now(),
        ]);
    }
}
