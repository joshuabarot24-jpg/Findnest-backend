<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\User>
 */
class UserFactory extends Factory
{
    protected $model = User::class;
    protected static ?string $password;

    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'password' => static::$password ??= Hash::make('password123'),
            'role' => 'student',
            'school_id' => fake()->unique()->numerify('202#-#####'),
            'course' => 'BS Information Technology',
            'year_level' => '3rd Year',
            'education_level' => 'college',
            'is_active' => true,
            'trust_score' => 100,
            'privileges' => [],
            'is_restricted' => false,
            'id_verified' => true,
            'remember_token' => Str::random(10),
        ];
    }

    public function student(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => 'student',
        ]);
    }

    public function admin(array $blockedPrivileges = []): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => 'admin',
            'school_id' => null,
            'course' => null,
            'year_level' => null,
            'privileges' => $blockedPrivileges,
        ]);
    }

    public function superAdmin(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => 'super_admin',
            'school_id' => null,
            'course' => null,
            'year_level' => null,
            'privileges' => [],
        ]);
    }

    public function withOtp(string $otp = '123456'): static
    {
        return $this->state(fn (array $attributes) => [
            'otp_code' => $otp,
            'otp_expires_at' => now()->addMinutes(10),
        ]);
    }

    public function restricted(string $reason = 'Violation of policies'): static
    {
        return $this->state(fn (array $attributes) => [
            'is_restricted' => true,
            'restriction_reason' => $reason,
        ]);
    }

    public function deactivated(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}
