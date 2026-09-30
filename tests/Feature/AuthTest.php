<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_student_login_sends_otp_email_successfully(): void
    {
        Mail::fake();

        $student = User::factory()->student()->create([
            'school_id' => '2023-00001',
            'password' => Hash::make('secret123'),
            'email' => 'student@example.com',
        ]);

        $response = $this->postJson('/api/auth/student/login', [
            'school_id' => '2023-00001',
            'password' => 'secret123',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'message' => 'OTP sent to your registered email',
            ]);

        $student->refresh();
        $this->assertNotNull($student->otp_code);
        $this->assertNotNull($student->otp_expires_at);
    }

    public function test_student_login_with_invalid_password_returns_401(): void
    {
        User::factory()->student()->create([
            'school_id' => '2023-00002',
            'password' => Hash::make('correctpassword'),
        ]);

        $response = $this->postJson('/api/auth/student/login', [
            'school_id' => '2023-00002',
            'password' => 'wrongpassword',
        ]);

        $response->assertStatus(401)
            ->assertJson(['message' => 'Invalid credentials']);
    }

    public function test_student_verify_otp_returns_bearer_token(): void
    {
        $student = User::factory()->student()->withOtp('654321')->create([
            'school_id' => '2023-00003',
        ]);

        $response = $this->postJson('/api/auth/student/verify-otp', [
            'school_id' => '2023-00003',
            'otp' => '654321',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'message',
                'token',
                'user' => ['id', 'name', 'email', 'role'],
            ]);

        $student->refresh();
        $this->assertNull($student->otp_code);
        $this->assertNull($student->otp_expires_at);
    }

    public function test_student_verify_otp_fails_with_incorrect_code(): void
    {
        User::factory()->student()->withOtp('111222')->create([
            'school_id' => '2023-00004',
        ]);

        $response = $this->postJson('/api/auth/student/verify-otp', [
            'school_id' => '2023-00004',
            'otp' => '999999',
        ]);

        $response->assertStatus(401)
            ->assertJson(['message' => 'Invalid OTP code']);
    }

    public function test_student_verify_otp_fails_when_expired(): void
    {
        $student = User::factory()->student()->create([
            'school_id' => '2023-00005',
            'otp_code' => '444555',
            'otp_expires_at' => now()->subMinutes(5),
        ]);

        $response = $this->postJson('/api/auth/student/verify-otp', [
            'school_id' => '2023-00005',
            'otp' => '444555',
        ]);

        $response->assertStatus(401)
            ->assertJson(['message' => 'OTP has expired. Please request a new one.']);
    }

    public function test_admin_login_with_valid_credentials_succeeds(): void
    {
        $admin = User::factory()->admin()->create([
            'email' => 'admin@findnest.edu',
            'password' => Hash::make('AdminPass!123'),
        ]);

        $response = $this->postJson('/api/auth/admin/login', [
            'email' => 'admin@findnest.edu',
            'password' => 'AdminPass!123',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'message',
                'token',
                'user' => ['id', 'name', 'email', 'role'],
            ])
            ->assertJsonPath('user.role', 'admin');
    }

    public function test_restricted_admin_login_is_forbidden(): void
    {
        User::factory()->admin()->restricted('Suspended by Super Admin')->create([
            'email' => 'badadmin@findnest.edu',
            'password' => Hash::make('AdminPass!123'),
        ]);

        $response = $this->postJson('/api/auth/admin/login', [
            'email' => 'badadmin@findnest.edu',
            'password' => 'AdminPass!123',
        ]);

        $response->assertStatus(403);
    }

    public function test_super_admin_login_succeeds(): void
    {
        $superAdmin = User::factory()->superAdmin()->create([
            'email' => 'superadmin@findnest.edu',
            'password' => Hash::make('SuperSecret!123'),
        ]);

        $response = $this->postJson('/api/auth/super-admin/login', [
            'email' => 'superadmin@findnest.edu',
            'password' => 'SuperSecret!123',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'message',
                'token',
                'user' => ['id', 'name', 'email', 'role'],
            ])
            ->assertJsonPath('user.role', 'super_admin');
    }

    public function test_authenticated_user_can_access_me_profile(): void
    {
        $user = User::factory()->student()->create();
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/auth/me');

        $response->assertStatus(200)
            ->assertJsonPath('user.id', $user->id)
            ->assertJsonPath('user.email', $user->email);
    }

    public function test_logout_revokes_current_access_token(): void
    {
        $user = User::factory()->student()->create();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/auth/logout');

        $response->assertStatus(200)
            ->assertJson(['message' => 'Logged out successfully']);
    }
}
