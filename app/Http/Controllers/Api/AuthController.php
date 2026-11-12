<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Carbon\Carbon;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    // Finds a student by student ID, or by email when the value contains an @
    private function findStudentByIdentifier(string $identifier): ?User
    {
        $identifier = trim($identifier);

        return User::where('role', 'student')
            ->where(function ($q) use ($identifier) {
                if (str_contains($identifier, '@')) {
                    $q->whereRaw('LOWER(email) = ?', [strtolower($identifier)]);
                } else {
                    $q->where('lrn', $identifier);
                }
            })
            ->first();
    }

    public function superAdminLogin(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $user = User::where('email', $request->email)
            ->where('role', 'super_admin')
            ->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            return response()->json(['message' => 'Invalid credentials'], 401);
        }

        if (!$user->is_active) {
            return response()->json(['message' => 'Account is deactivated'], 403);
        }

        if (!\App\Support\DemoAccounts::skipsOtp($user->email)) {
            return $this->staffOtpStep($user);
        }

        $user->tokens()->delete();

        $token = $user->createToken('super-admin-token')->plainTextToken;

        AuditLog::create([
            'user_id' => $user->id,
            'action' => 'Super Admin Login',
            'target_type' => 'users',
            'target_id' => $user->id,
            'details' => 'Super Admin logged in successfully',
            'performed_by' => 'Super Admin: ' . $user->name,
            'ip_address' => $request->ip(),
        ]);

        return response()->json([
            'message' => 'Login successful',
            'token' => $token,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
            ]
        ]);
    }

    public function unifiedLogin(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'identifier' => 'required|string|max:50',
            'password' => 'required|string|max:50',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $identifier = trim($request->identifier);
        $isEmail = str_contains($identifier, '@');

        if (\App\Models\SystemSetting::get('maintenance_mode', '0') === '1') {
            $user = $isEmail
                ? User::where('email', $identifier)->first()
                : User::where('lrn', $identifier)->first();

            if ($user && $user->role !== 'super_admin') {
                return response()->json(['message' => 'The system is currently under maintenance. Please try again later.'], 503);
            }
        }

        if ($isEmail) {
            // An email can belong to a super admin, an admin, or a student
            $staff = User::where('email', $identifier)
                ->whereIn('role', ['super_admin', 'admin'])
                ->first();

            // Not staff: this may be a student logging in with their email
            if (!$staff) {
                return $this->studentPasswordStep($request, $this->findStudentByIdentifier($identifier));
            }

            $user = $staff;

            if ($user->login_locked_until && now()->lt($user->login_locked_until)) {
                $wait = max(1, (int) ceil(now()->diffInSeconds($user->login_locked_until, false)));
                return response()->json([
                    'message' => 'Too many failed login attempts. Please try again later.',
                    'retry_after' => $wait,
                ], 429);
            }

            if (!Hash::check($request->password, $user->password)) {
                \App\Support\LoginLock::fail($user);
                return response()->json(['message' => 'Invalid credentials'], 401);
            }

            $user->update(['failed_login_attempts' => 0, 'login_locked_until' => null]);

            if (!$user->is_active) {
                return response()->json(['message' => 'Account is deactivated'], 403);
            }

            if ($user->role === 'admin' && (new \App\Services\TrustScoreService())->isRestricted($user)) {
                return response()->json(['message' => 'Your account has been suspended by the Super Admin. Please contact them for assistance.'], 403);
            }

            if (!\App\Support\DemoAccounts::skipsOtp($user->email)) {
                return $this->staffOtpStep($user);
            }

            $user->tokens()->delete();
            $tokenName = $user->role === 'super_admin' ? 'super-admin-token' : 'admin-token';
            $token = $user->createToken($tokenName)->plainTextToken;

            AuditLog::create([
                'user_id' => $user->id,
                'action' => $user->role === 'super_admin' ? 'Super Admin Login' : 'Admin Login',
                'target_type' => 'users',
                'target_id' => $user->id,
                'details' => ucfirst(str_replace('_', ' ', $user->role)) . ' logged in successfully',
                'performed_by' => ucfirst(str_replace('_', ' ', $user->role)) . ': ' . $user->name,
                'ip_address' => $request->ip(),
            ]);

            return response()->json([
                'message' => 'Login successful',
                'requires_otp' => false,
                'token' => $token,
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->role,
                ],
            ]);
        }

        return $this->studentPasswordStep($request, $this->findStudentByIdentifier($identifier));
    }

    // The password step for a student: checks the account, then sends the email code
    private function studentPasswordStep(Request $request, ?User $user)
    {
        if (!$user) {
            return response()->json(['message' => 'Invalid credentials'], 401);
        }

        if ($user->login_locked_until && now()->lt($user->login_locked_until)) {
            $wait = max(1, (int) ceil(now()->diffInSeconds($user->login_locked_until, false)));
            return response()->json([
                'message' => 'Too many failed login attempts. Please try again later.',
                'retry_after' => $wait,
            ], 429);
        }

        if (!Hash::check($request->password, $user->password)) {
            \App\Support\LoginLock::fail($user);
            return response()->json(['message' => 'Invalid credentials'], 401);
        }

        $user->update(['failed_login_attempts' => 0, 'login_locked_until' => null]);

        if (!$user->is_active) {
            return response()->json(['message' => 'Account is deactivated'], 403);
        }

        // isRestricted() also lifts a restriction whose days have run out
        if ((new \App\Services\TrustScoreService())->isRestricted($user)) {
            return response()->json(['message' => 'Your account has been restricted. Please contact the Guidance Office for assistance.'], 403);
        }

        if (\App\Support\DemoAccounts::skipsOtp($user->email)) {
            return $this->issueStudentSession($user, $request);
        }

        $this->sendOtpEmail($user);

        return response()->json([
            'message' => 'OTP sent to your registered email',
            'requires_otp' => true,
            'school_id' => $user->school_id,
            'email' => substr($user->email, 0, 3) . '****@' . explode('@', $user->email)[1],
        ]);
    }

    // Admin or super admin: send the code instead of a session. The web sends "school_id" (the email) back with it
    // Demo student accounts only: a session without the email code
    private function issueStudentSession(User $user, Request $request)
    {
        $user->tokens()->delete();
        $token = $user->createToken('student-token')->plainTextToken;

        AuditLog::create([
            'user_id' => $user->id,
            'action' => 'Student Login',
            'target_type' => 'users',
            'target_id' => $user->id,
            'details' => 'Student logged in (demo account, no email code)',
            'performed_by' => 'Student: ' . $user->name,
            'ip_address' => $request->ip(),
        ]);

        return response()->json([
            'message' => 'Login successful',
            'requires_otp' => false,
            'token' => $token,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
                'school_id' => $user->school_id,
                'trust_score' => $user->trust_score,
            ],
        ]);
    }

    private function staffOtpStep(User $user)
    {
        $this->sendOtpEmail($user);
        [$name, $domain] = array_pad(explode('@', (string) $user->email, 2), 2, '');

        return response()->json([
            'message' => 'OTP sent to your registered email',
            'requires_otp' => true,
            'email' => substr($name, 0, 2) . str_repeat('*', max(2, strlen($name) - 2)) . '@' . $domain,
            'school_id' => $user->email,
        ]);
    }

    // Creates the session for an admin or super admin, after the code is verified (or for the demo accounts)
    private function issueStaffSession(User $user, Request $request)
    {
        $user->tokens()->delete();
        $token = $user->createToken($user->role === 'super_admin' ? 'super-admin-token' : 'admin-token')->plainTextToken;
        $label = ucfirst(str_replace('_', ' ', $user->role));

        AuditLog::create([
            'user_id' => $user->id,
            'action' => $label . ' Login',
            'target_type' => 'users',
            'target_id' => $user->id,
            'details' => $label . ' logged in successfully',
            'performed_by' => $label . ': ' . $user->name,
            'ip_address' => $request->ip(),
        ]);

        return response()->json([
            'message' => 'Login successful',
            'requires_otp' => false,
            'token' => $token,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
            ],
        ]);
    }

    // Saves a new 6-digit code on the student and emails it
    private function sendOtpEmail(User $user): void
    {
        \App\Services\OtpMailer::send($user);
    }

    public function adminLogin(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        if (\App\Models\SystemSetting::get('maintenance_mode', '0') === '1') {
            return response()->json(['message' => 'The system is currently under maintenance. Please try again later.'], 503);
        }

        $user = User::where('email', $request->email)
            ->where('role', 'admin')
            ->first();

        if (!$user) {
            return response()->json(['message' => 'Invalid credentials'], 401);
        }

        if ($user->login_locked_until && now()->lt($user->login_locked_until)) {
            $wait = max(1, (int) ceil(now()->diffInSeconds($user->login_locked_until, false)));
            return response()->json([
                'message' => 'Too many failed login attempts. Please try again later.',
                'retry_after' => $wait,
            ], 429);
        }

        if (!Hash::check($request->password, $user->password)) {
            \App\Support\LoginLock::fail($user);
            return response()->json(['message' => 'Invalid credentials'], 401);
        }

        $user->update(['failed_login_attempts' => 0, 'login_locked_until' => null]);

        if (!$user->is_active) {
            return response()->json(['message' => 'Account is deactivated'], 403);
        }

        if ((new \App\Services\TrustScoreService())->isRestricted($user)) {
            return response()->json(['message' => 'Your account has been suspended by the Super Admin. Please contact them for assistance.'], 403);
        }

        if (!\App\Support\DemoAccounts::skipsOtp($user->email)) {
            return $this->staffOtpStep($user);
        }

        $user->tokens()->delete();

        $token = $user->createToken('admin-token')->plainTextToken;

        AuditLog::create([
            'user_id' => $user->id,
            'action' => 'Admin Login',
            'target_type' => 'users',
            'target_id' => $user->id,
            'details' => 'Admin logged in successfully',
            'performed_by' => 'Admin: ' . $user->name,
            'ip_address' => $request->ip(),
        ]);

        return response()->json([
            'message' => 'Login successful',
            'token' => $token,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
            ]
        ]);
    }

    public function studentLogin(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'school_id' => 'required|string',
            'password' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        if (\App\Models\SystemSetting::get('maintenance_mode', '0') === '1') {
            return response()->json(['message' => 'The system is currently under maintenance. Please try again later.'], 503);
        }

        return $this->studentPasswordStep($request, $this->findStudentByIdentifier($request->school_id));
    }

    public function verifyOtp(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'school_id' => 'required|string',
            'otp' => 'required|string|size:6',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $staff = str_contains($request->school_id, '@')
            ? User::where('email', $request->school_id)->whereIn('role', ['admin', 'super_admin'])->first()
            : null;
        $user = $staff ?: $this->findStudentByIdentifier($request->school_id);

        if (!$user) {
            return response()->json(['message' => 'Account not found'], 404);
        }

        if ($user->otp_code !== $request->otp) {
            return response()->json(['message' => 'Invalid OTP code'], 401);
        }

        if (Carbon::now()->isAfter($user->otp_expires_at)) {
            return response()->json(['message' => 'OTP has expired. Please request a new one.'], 401);
        }

        $user->tokens()->delete();

        $user->update([
            'otp_code' => null,
            'otp_expires_at' => null,
        ]);

        if ($user->role !== 'student') {
            return $this->issueStaffSession($user, $request);
        }

        $token = $user->createToken('student-token')->plainTextToken;

        AuditLog::create([
            'user_id' => $user->id,
            'action' => 'Student Login',
            'target_type' => 'users',
            'target_id' => $user->id,
            'details' => 'Student verified OTP and logged in successfully',
            'performed_by' => 'Student: ' . $user->name,
            'ip_address' => $request->ip(),
        ]);

        return response()->json([
            'message' => 'Login successful',
            'token' => $token,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
                'school_id' => $user->school_id,
                'trust_score' => $user->trust_score,
            ]
        ]);
    }

    public function resendOtp(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'school_id' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $staff = str_contains($request->school_id, '@')
            ? User::where('email', $request->school_id)->whereIn('role', ['admin', 'super_admin'])->first()
            : null;
        $user = $staff ?: $this->findStudentByIdentifier($request->school_id);

        if (!$user) {
            return response()->json(['message' => 'Account not found'], 404);
        }

        if ($user->otp_expires_at) {
            $lastSentAt = Carbon::parse($user->otp_expires_at)->subMinutes(10);
            $secondsSinceLastSend = $lastSentAt->diffInSeconds(Carbon::now());

            if ($secondsSinceLastSend < 60) {
                return response()->json([
                    'message' => 'Please wait before requesting another code.',
                    'retry_after' => 60 - $secondsSinceLastSend,
                ], 429);
            }
        }

        $this->sendOtpEmail($user);

        return response()->json([
            'message' => 'New OTP sent to your registered email',
        ]);
    }

    public function resetPassword(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
            'token' => 'required|string',
            'password' => ['required', 'string', new \App\Rules\StrongPassword(), 'confirmed'],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $record = \DB::table('password_reset_tokens')->where('email', $request->email)->first();

        if (!$record) {
            return response()->json(['message' => 'Invalid or expired reset link.'], 400);
        }

        if (now()->diffInMinutes($record->created_at) > 60) {
            \DB::table('password_reset_tokens')->where('email', $request->email)->delete();
            return response()->json(['message' => 'This reset link has expired. Please request a new one.'], 400);
        }

        if (!hash_equals($record->token, hash('sha256', $request->token))) {
            return response()->json(['message' => 'Invalid or expired reset link.'], 400);
        }

        $user = User::where('email', $request->email)->first();

        if (!$user) {
            return response()->json(['message' => 'User not found.'], 404);
        }

        if (!$user->is_active) {
            return response()->json(['message' => 'This account is archived. Please contact the Super Admin.'], 403);
        }

        $user->update([
            'password' => Hash::make($request->password),
            'failed_login_attempts' => 0,
            'login_locked_until' => null,
        ]);
        $user->forceFill(['password_is_temporary' => false])->save();

        \DB::table('password_reset_tokens')->where('email', $request->email)->delete();

        return response()->json(['message' => 'Password reset successfully. You can now log in with your new password.']);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logged out successfully']);
    }

    public function me(Request $request)
    {
        return response()->json(['user' => $request->user()]);
    }

    public function forgotPassword(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $user = User::where('email', $request->email)->first();

        if (!$user) {
            return response()->json(['message' => 'If that email exists in our system, a reset link has been sent.']);
        }

        $token = Str::random(64);

        \DB::table('password_reset_tokens')->updateOrInsert(
            ['email' => $user->email],
            ['token' => hash('sha256', $token), 'created_at' => now()]
        );

        // [Fix] Reset link must point to the live website, not localhost. Set FRONTEND_URL to override (e.g. local testing).
        $frontendUrl = rtrim(env('FRONTEND_URL', 'https://findnest.org'), '/');
        $resetUrl = $frontendUrl . "/reset-password?token={$token}&email=" . urlencode($user->email);

        \Mail::raw(
            "Hello {$user->name},\n\nWe received a request to reset your FindNest password. Click the link below to set a new password. This link expires in 60 minutes.\n\n{$resetUrl}\n\nIf you did not request this, please ignore this email.",
            function ($message) use ($user) {
                $message->to($user->email)->subject('FindNest - Reset Your Password');
            }
        );

        return response()->json(['message' => 'If that email exists in our system, a reset link has been sent.']);
    }
}
