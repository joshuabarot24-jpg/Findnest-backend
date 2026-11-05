<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;


class ProfileController extends Controller
{
    public function show(Request $request)
    {
        $user = $request->user();
        // The apps read manual_override_granted, so send the effective value: false once the 24 hours have passed
        $user->manual_override_granted = $user->hasActiveOverride();

        return response()->json(['user' => $user]);
    }

    public function update(Request $request)
    {
        $user = $request->user();

        if ($user->role === 'student') {
            $validator = Validator::make($request->all(), [
                'email' => ['required', 'email', 'unique:users,email,' . $user->id, new \App\Rules\GmailOnly($user->email)],
            ]);

            if ($validator->fails()) {
                return response()->json(['errors' => $validator->errors()], 422);
            }

            $oldEmail = $user->email;
            $user->update(['email' => $request->email]);

            AuditLog::create([
                'user_id' => $user->id,
                'action' => 'Email Updated',
                'target_type' => 'users',
                'target_id' => $user->id,
                'details' => 'Student changed their email from ' . $oldEmail . ' to ' . $request->email,
                'performed_by' => 'Student: ' . $user->name,
                'ip_address' => $request->ip(),
            ]);

            return response()->json([
                'message' => 'Email updated successfully',
                'user' => $user->fresh(),
            ]);
        }

        $validator = Validator::make($request->all(), [
            'name'       => 'sometimes|string|max:255',
            'school_id'  => 'sometimes|nullable|string|max:50',
            'course'     => 'sometimes|nullable|string|max:100',
            'year_level' => 'sometimes|nullable|string|max:50',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $user->update($request->only(['name', 'school_id', 'course', 'year_level']));

        return response()->json([
            'message' => 'Profile updated successfully',
            'user'    => $user->fresh(),
        ]);
    }

    // Saves the student's profile photo (already uploaded through /upload/image, folder "profiles")
    public function updatePhoto(Request $request)
    {
        $user = $request->user();

        $validator = Validator::make($request->all(), [
            'photo_url' => ['required', 'string', 'max:500', 'starts_with:https://res.cloudinary.com/'],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $user->forceFill(['profile_photo_url' => $request->photo_url])->save();

        return response()->json(['message' => 'Profile photo updated.', 'user' => $user->fresh()]);
    }

    // Removes the profile photo; the initial letter shows again
    public function removePhoto(Request $request)
    {
        $user = $request->user();
        $user->forceFill(['profile_photo_url' => null])->save();

        return response()->json(['message' => 'Profile photo removed.', 'user' => $user->fresh()]);
    }

    public function changePassword(Request $request)
    {
        $user = $request->user();

        if ($user->role === 'student') {
            return response()->json([
                'message' => 'Students cannot change their password directly. Please submit a password change request instead.',
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'current_password' => 'required|string',
            'new_password'     => 'required|string|min:8|confirmed',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        if (!Hash::check($request->current_password, $user->password)) {
            return response()->json(['message' => 'Current password is incorrect.'], 422);
        }

        $user->update(['password' => Hash::make($request->new_password)]);

        return response()->json(['message' => 'Password changed successfully']);
    }

    public function requestPasswordChange(Request $request)
    {
        $user = $request->user();

        $validator = Validator::make($request->all(), [
            'reason' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        if ($user->password_change_requested) {
            return response()->json(['message' => 'You already have a pending password change request.'], 409);
        }

        $user->update([
            'password_change_requested' => true,
            'password_change_reason' => $request->reason,
        ]);

        AuditLog::create([
            'user_id' => $user->id,
            'action' => 'Password Change Requested',
            'target_type' => 'users',
            'target_id' => $user->id,
            'details' => 'Student requested a password change: ' . $request->reason,
            'performed_by' => 'Student: ' . $user->name,
            'ip_address' => $request->ip(),
        ]);

        return response()->json(['message' => 'Your password change request has been sent to the Super Admin.']);
    }
    // Emails a fresh code to a student who must change a temporary password
    public function sendPasswordOtp(Request $request)
    {
        $user = $request->user();

        if (!$user->password_is_temporary) {
            return response()->json(['message' => 'A verification code is not needed.'], 403);
        }

        if ($user->otp_expires_at) {
            $sentAt = \Carbon\Carbon::parse($user->otp_expires_at)->subMinutes(10);
            $since = $sentAt->diffInSeconds(now(), false);
            if ($since >= 0 && $since < 60) {
                return response()->json([
                    'message' => 'Please wait before requesting another code.',
                    'retry_after' => (int) (60 - $since),
                ], 429);
            }
        }

        \App\Services\OtpMailer::send($user);

        return response()->json(['message' => 'A 6-digit verification code has been sent to your registered email address.']);
    }

    public function setNewPassword(Request $request)
    {
        $user = $request->user();
        $isTemporary = (bool) $user->password_is_temporary;

        if (!$user->password_change_approved && !$isTemporary) {
            return response()->json(['message' => 'Your password change has not been approved yet.'], 403);
        }

        $validator = Validator::make($request->all(), [
        'new_password' => ['required', 'string', new \App\Rules\StrongPassword(), 'confirmed'],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // A temporary password also needs the code sent to the student's email
        if ($isTemporary) {
            $code = (string) $request->input('otp_code');
            $valid = $user->otp_code
                && hash_equals((string) $user->otp_code, $code)
                && $user->otp_expires_at
                && now()->lt(\Carbon\Carbon::parse($user->otp_expires_at));

            if (!$valid) {
                return response()->json(['message' => 'Invalid or expired verification code. Please request a new one.'], 422);
            }
        }

        if (Hash::check($request->new_password, $user->password)) {
            return response()->json(['message' => 'Your new password must be different from your current one.'], 422);
        }

        $user->update([
            'password' => Hash::make($request->new_password),
            'password_last_changed_at' => now(),
            'password_change_requested' => false,
            'password_change_approved' => false,
            'password_change_reason' => null,
        ]);
        $user->forceFill(['password_is_temporary' => false, 'otp_code' => null, 'otp_expires_at' => null])->save();

        AuditLog::create([
            'user_id' => $user->id,
            'action' => 'Password Changed',
            'target_type' => 'users',
            'target_id' => $user->id,
            'details' => $isTemporary
                ? 'Student replaced their temporary password with their own'
                : 'Student set a new password after Super Admin approval',
            'performed_by' => 'Student: ' . $user->name,
            'ip_address' => $request->ip(),
        ]);

        return response()->json(['message' => 'Password changed successfully']);
    }

        public function verifyId(Request $request)
    {
        $user = $request->user();

        $validator = Validator::make($request->all(), [
            'id_photo_url' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $idService = new \App\Services\IdVerificationService();
        $extraction = $idService->extractIdInfo($request->id_photo_url);

        if (!$extraction['success']) {
            return response()->json(['message' => $extraction['message']], 422);
        }

        $status = $idService->compareToAccount(
            $extraction['name'],
            $extraction['school_id'],
            $user->name,
            $user->school_id
        );

        $user->update([
            'id_verified' => true,
            'id_photo_url' => $request->id_photo_url,
            'id_extracted_name' => $extraction['name'],
            'id_extracted_school_id' => $extraction['school_id'],
            'identity_verification_status' => $status,
        ]);

        return response()->json([
            'message' => $status === 'matched'
                ? 'Your ID has been verified successfully.'
                : 'Your ID was uploaded, but some details did not fully match your account. This has been flagged for admin review.',
            'status' => $status,
        ]);
    }
}
