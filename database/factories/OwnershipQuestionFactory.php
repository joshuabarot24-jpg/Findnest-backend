<?php

namespace Database\Factories;

use App\Models\Claim;
use App\Models\OwnershipQuestion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\OwnershipQuestion>
 */
class OwnershipQuestionFactory extends Factory
{
    protected $model = OwnershipQuestion::class;

    public function definition(): array
    {
        return [
            'claim_id' => Claim::factory(),
            'question_text' => 'What is the color of the keychain attached to the item?',
            'option_a' => 'Blue with star',
            'option_b' => 'Red ribbon',
            'option_c' => 'No keychain',
            'option_d' => 'Yellow smiley',
            'correct_option' => 'A',
            'student_answer' => null,
        ];
    }

    public function answered(string $answer = 'A'): static
    {
        return $this->state(fn (array $attributes) => [
            'student_answer' => $answer,
        ]);
    }
}
