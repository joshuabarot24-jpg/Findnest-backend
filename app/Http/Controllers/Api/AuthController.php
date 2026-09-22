<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Carbon\Carbon;
use Illuminate\Support\Str;

class AuthController extends Controller
{
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

        if (!$user || !Hash::check($request->password, $user->password)) {
            return response()->json(['message' => 'Invalid credentials'], 401);
        }

        if (!$user->is_active) {
            return response()->json(['message' => 'Account is deactivated'], 403);
        }

        if ($user->is_restricted) {
            return response()->json(['message' => 'Your account has been suspended by the Super Admin. Please contact them for assistance.'], 403);
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

        $user = User::where('school_id', $request->school_id)
            ->where('role', 'student')
            ->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            return response()->json(['message' => 'Invalid credentials'], 401);
        }

        if (!$user->is_active) {
            return response()->json(['message' => 'Account is deactivated'], 403);
        }

        if ($user->is_restricted) {
            return response()->json(['message' => 'Your account has been restricted. Please contact the Guidance Office for assistance.'], 403);
        }

        $otp = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $user->update([
            'otp_code' => $otp,
            'otp_expires_at' => Carbon::now()->addMinutes(10),
        ]);

        $emailHtml = '
        <div style="font-family: Arial, sans-serif; max-width: 400px; margin: 0 auto; padding: 30px 20px; text-align: center;">
            <p style="font-size: 20px; font-weight: 900; color: #1a237e; margin-bottom: 4px;">
                FIND<span style="color: #c99700;">NEST</span>
            </p>
            <p style="color: #9ca3af; font-size: 12px; margin-bottom: 30px;">SJDM Cornerstone College Inc.</p>
            <p style="color: #374151; font-size: 14px; margin-bottom: 20px;">Your verification code is:</p>
            <p style="font-size: 36px; font-weight: 900; letter-spacing: 8px; color: #1a237e; margin: 0 0 20px 0;">' . $otp . '</p>
            <p style="color: #9ca3af; font-size: 12px;">This code expires in 10 minutes.</p>
            <p style="color: #9ca3af; font-size: 11px; margin-top: 30px;">Do not share this code with anyone.</p>
        </div>';

        Mail::html($emailHtml, function ($message) use ($user) {
            $message->to($user->email)
                ->subject('FindNest — Your Verification Code');
        });

        return response()->json([
            'message' => 'OTP sent to your registered email',
            'email' => substr($user->email, 0, 3) . '****@' . explode('@', $user->email)[1],
        ]);
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

        $user = User::where('school_id', $request->school_id)
            ->where('role', 'student')
            ->first();

        if (!$user) {
            return response()->json(['message' => 'Student not found'], 404);
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
                'course' => $user->course,
                'year_level' => $user->year_level,
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

        $user = User::where('school_id', $request->school_id)
            ->where('role', 'student')
            ->first();

        if (!$user) {
            return response()->json(['message' => 'Student not found'], 404);
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

        $otp = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $user->update([
            'otp_code' => $otp,
            'otp_expires_at' => Carbon::now()->addMinutes(10),
        ]);

        $emailHtml = '
        <div style="font-family: Arial, sans-serif; max-width: 400px; margin: 0 auto; padding: 30px 20px; text-align: center;">
            <p style="font-size: 20px; font-weight: 900; color: #1a237e; margin-bottom: 4px;">
                FIND<span style="color: #c99700;">NEST</span>
            </p>
            <p style="color: #9ca3af; font-size: 12px; margin-bottom: 30px;">SJDM Cornerstone College Inc.</p>
            <p style="color: #374151; font-size: 14px; margin-bottom: 20px;">Your verification code is:</p>
            <p style="font-size: 36px; font-weight: 900; letter-spacing: 8px; color: #1a237e; margin: 0 0 20px 0;">' . $otp . '</p>
            <p style="color: #9ca3af; font-size: 12px;">This code expires in 10 minutes.</p>
            <p style="color: #9ca3af; font-size: 11px; margin-top: 30px;">Do not share this code with anyone.</p>
        </div>';

        Mail::html($emailHtml, function ($message) use ($user) {
            $message->to($user->email)
                ->subject('FindNest — Your Verification Code');
        });

        return response()->json([
            'message' => 'New OTP sent to your registered email',
        ]);
    }

    public function resetPassword(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
            'token' => 'required|string',
            'password' => 'required|string|min:8|confirmed',
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

        $user->update(['password' => Hash::make($request->password)]);

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

        $resetUrl = "http://localhost:3000/reset-password?token={$token}&email=" . urlencode($user->email);

        \Mail::raw(
            "Hello {$user->name},\n\nWe received a request to reset your FindNest password. Click the link below to set a new password. This link expires in 60 minutes.\n\n{$resetUrl}\n\nIf you did not request this, please ignore this email.",
            function ($message) use ($user) {
                $message->to($user->email)->subject('FindNest - Reset Your Password');
            }
        );

        return response()->json(['message' => 'If that email exists in our system, a reset link has been sent.']);
    }

}
