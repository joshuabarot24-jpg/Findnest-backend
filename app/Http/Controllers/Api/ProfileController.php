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
        return response()->json(['user' => $request->user()]);
    }

    public function update(Request $request)
    {
        $user = $request->user();

        if ($user->role === 'student') {
            return response()->json([
                'message' => 'Your personal information is managed by the school. Contact the Guidance Office to request changes.',
            ], 403);
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
    public function setNewPassword(Request $request)
    {
        $user = $request->user();

        if (!$user->password_change_approved) {
            return response()->json(['message' => 'Your password change has not been approved yet.'], 403);
        }

        $validator = Validator::make($request->all(), [
            'new_password' => 'required|string|min:8|confirmed',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $user->update([
            'password' => Hash::make($request->new_password),
            'password_last_changed_at' => now(),
            'password_change_requested' => false,
            'password_change_approved' => false,
            'password_change_reason' => null,
        ]);

        AuditLog::create([
            'user_id' => $user->id,
            'action' => 'Password Changed',
            'target_type' => 'users',
            'target_id' => $user->id,
            'details' => 'Student set a new password after Super Admin approval',
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
