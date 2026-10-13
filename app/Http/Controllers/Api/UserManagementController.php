<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Carbon\Carbon;
use App\Models\LostItemReport;
use App\Models\FoundItemRecord;
use App\Models\Claim;
class UserManagementController extends Controller
    {
    public function index()
    {
        $users = User::orderBy('created_at', 'desc')->get();
        return response()->json(['users' => $users]);
    }

   public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'email' => ['required', 'email', 'unique:users,email', new \App\Rules\GmailOnly()],
            'password' => ['required', 'string', new \App\Rules\StrongPassword()],
            'role' => 'required|in:super_admin,admin,student',
            'school_id' => 'nullable|string|unique:users,school_id',
            'course' => 'nullable|string',
            'year_level' => 'nullable|string',
            'education_level' => 'nullable|in:college,senior_high_school,junior_high_school,high_school',
            'privileges' => 'nullable|array',
            'is_restricted' => 'nullable|boolean',
            'restriction_reason' => 'nullable|string',
            'restricted_until' => 'nullable|date',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'role' => $request->role,
            'school_id' => $request->school_id,
            'course' => $request->course,
            'year_level' => $request->year_level,
            'education_level' => $request->education_level,
            'privileges' => $request->privileges,
            'is_restricted' => $request->is_restricted ?? false,
            'restriction_reason' => $request->restriction_reason,
            'restricted_until' => $request->restricted_until,
            'is_active' => true,
            'trust_score' => 100,
        ]);

        if ($user->role === 'student') {
            $user->forceFill(['password_is_temporary' => true])->save();
        }

        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => 'User Created',
            'target_type' => 'users',
            'target_id' => $user->id,
            'details' => 'New user created: ' . $user->name . ' (' . $user->role . ')',
            'performed_by' => 'Super Admin: ' . $request->user()->name,
            'ip_address' => $request->ip(),
        ]);

        return response()->json([
            'message' => 'User created successfully',
            'user' => $user
        ], 201);
    }

    public function update(Request $request, $id)
    {
        $user = User::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'email' => ['required', 'email', 'unique:users,email,' . $id, new \App\Rules\GmailOnly($user->email)],
            'password' => ['nullable', 'string', new \App\Rules\StrongPassword()],
            'role' => 'required|in:super_admin,admin,student',
            'school_id' => 'nullable|string|unique:users,school_id,' . $id,
            'course' => 'nullable|string',
            'year_level' => 'nullable|string',
            'education_level' => 'nullable|in:college,senior_high_school,junior_high_school,high_school',
            'privileges' => 'nullable|array',
            'is_restricted' => 'nullable|boolean',
            'restriction_reason' => 'nullable|string',
            'restricted_until' => 'nullable|date',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $user->update([
            'name' => $request->name,
            'email' => $request->email,
            'role' => $request->role,
            'school_id' => $request->school_id,
            'course' => $request->course,
            'year_level' => $request->year_level,
            'education_level' => $request->education_level,
            'privileges' => $request->privileges,
            'is_restricted' => $request->is_restricted ?? false,
            'restriction_reason' => $request->restriction_reason,
            'restricted_until' => $request->restricted_until,
        ]);

                if ($request->password) {
            $user->update(['password' => Hash::make($request->password)]);

            if ($user->role === 'student') {
                $user->forceFill([
                    'password_is_temporary' => true,
                    'password_change_requested' => false,
                    'password_change_approved' => false,
                    'password_change_reason' => null,
                ])->save();
            }
        }

        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => 'User Updated',
            'target_type' => 'users',
            'target_id' => $user->id,
            'details' => 'User updated: ' . $user->name,
            'performed_by' => 'Super Admin: ' . $request->user()->name,
            'ip_address' => $request->ip(),
        ]);

        return response()->json([
            'message' => 'User updated successfully',
            'user' => $user
        ]);
    }

    public function revoke(Request $request, $id)
    {
        $user = User::findOrFail($id);

        // [Sec] The Super Admin account can never be archived (it would lock everyone out of management).
        if ($user->role === 'super_admin') {
            return response()->json(['message' => 'The Super Admin account cannot be archived.'], 403);
        }
        // Archiving clears the restored marker, so the account leaves the Restored tab
        $user->update(['is_active' => false, 'restored_at' => null]);

        AuditLog::create([
            'user_id' => $request->user()->id,
             'action' => 'User Archived',
            'target_type' => 'users',
            'target_id' => $user->id,
            'details' => 'User account archived: ' . $user->name,
            'performed_by' => 'Super Admin: ' . $request->user()->name,
            'ip_address' => $request->ip(),
        ]);

         return response()->json(['message' => 'User archived successfully', 'user' => $user]);
    }

    // Restores an archived account. A new password is required and is marked temporary,
    // so the person must set their own at first login.
        // Restores an archived account.
    // Students: a new password is required and is marked temporary (they must replace it at first login).
    // Admins: the existing password is kept; the account is simply reactivated.
    public function restore(Request $request, $id)
    {
        $user = User::findOrFail($id);
        $isStudent = $user->role === 'student';

        if ($isStudent) {
            $validator = Validator::make($request->all(), [
                'new_password' => ['required', 'string', new \App\Rules\StrongPassword()],
            ]);

            if ($validator->fails()) {
                return response()->json(['errors' => $validator->errors()], 422);
            }
        }

        $updates = [
            'is_active' => true,
            'failed_login_attempts' => 0,
            'login_locked_until' => null,
            'restored_at' => now(),
        ];
        if ($isStudent) {
            $updates['password'] = Hash::make($request->new_password);
        }
        $user->update($updates);

        if ($isStudent) {
            $user->forceFill(['password_is_temporary' => true])->save();
        }

        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => 'User Restored',
            'target_type' => 'users',
            'target_id' => $user->id,
            'details' => $isStudent
                ? 'Archived account restored with a new temporary password: ' . $user->name
                : 'Archived account restored: ' . $user->name,
            'performed_by' => 'Super Admin: ' . $request->user()->name,
            'ip_address' => $request->ip(),
        ]);

        return response()->json([
            'message' => $isStudent ? 'Account restored. Give the new password to the user.' : 'Account restored.',
            'user' => $user->fresh(),
        ]);
    }

    public function approvePasswordChange(Request $request, $id)
    {
        $user = User::findOrFail($id);

        if (!$user->password_change_requested) {
            return response()->json(['message' => 'This student has no pending password change request.'], 422);
        }

        $user->update([
            'password_change_approved' => true,
        ]);

        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => 'Password Change Approved',
            'target_type' => 'users',
            'target_id' => $user->id,
            'details' => 'Super Admin approved password change request for ' . $user->name,
            'performed_by' => 'Super Admin: ' . $request->user()->name,
            'ip_address' => $request->ip(),
        ]);

        return response()->json(['message' => 'Password change approved. The student can now set a new password.', 'user' => $user]);
    }

    // Read-only overview of what a student has posted (lost reports, found reports, claims).
    // Admin and Super Admin only; works for archived students too.
    public function activity(Request $request, $id)
    {
        if (!in_array($request->user()->role, ['admin', 'super_admin'])) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $student = User::where('id', $id)->where('role', 'student')->first();
        if (!$student) {
            return response()->json(['message' => 'Student not found.'], 404);
        }

        $lost = LostItemReport::where('user_id', $student->id)
            ->orderBy('created_at', 'desc')
            ->get();

        $found = FoundItemRecord::where('admin_id', $student->id)
            ->orderBy('created_at', 'desc')
            ->get();

        $claims = Claim::where('student_id', $student->id)
            ->with(['match.foundRecord', 'match.lostReport'])
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'student' => ['id' => $student->id, 'name' => $student->name],
            'lost_reports' => $lost,
            'found_reports' => $found,
            'claims' => $claims,
        ]);
    }

    public function toggleRestriction(Request $request, $id)
    {
        $user = User::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'is_restricted' => 'required|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $user->update([
            'is_restricted' => $request->is_restricted,
        ]);

        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => $request->is_restricted ? 'Account Restricted' : 'Restriction Lifted',
            'target_type' => 'users',
            'target_id' => $user->id,
            'details' => ($request->is_restricted ? 'Restricted' : 'Unrestricted') . ' account: ' . $user->name,
            'performed_by' => ($request->user()->role === 'super_admin' ? 'Super Admin: ' : 'Admin: ') . $request->user()->name,
            'ip_address' => $request->ip(),
        ]);

        return response()->json([
            'message' => $request->is_restricted ? 'Account restricted' : 'Restriction lifted',
            'user' => $user->fresh(),
        ]);
    }

    public function toggleManualOverride(Request $request, $id)
    {
        $user = User::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'manual_override_granted' => 'required|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $user->update([
            'manual_override_granted' => $request->manual_override_granted,
        ]);

        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => $request->manual_override_granted ? 'Manual Override Enabled' : 'Manual Override Disabled',
            'target_type' => 'users',
            'target_id' => $user->id,
            'details' => ($request->manual_override_granted ? 'Enabled' : 'Disabled') . ' manual AI override for account: ' . $user->name,
            'performed_by' => ($request->user()->role === 'super_admin' ? 'Super Admin: ' : 'Admin: ') . $request->user()->name,
            'ip_address' => $request->ip(),
        ]);

        return response()->json([
            'message' => $request->manual_override_granted ? 'Manual override enabled' : 'Manual override disabled',
            'user' => $user->fresh(),
        ]);
    }

    public function destroy(Request $request, $id)
    {
        $user = User::findOrFail($id);

        if ($user->role !== 'student') {
            return response()->json(['message' => 'Only student accounts can be deleted this way.'], 403);
        }

        if ($user->is_active) {
            return response()->json(['message' => 'This account must be revoked before it can be permanently deleted.'], 422);
        }

        $name = $user->name;

        \App\Models\OwnershipQuestion::whereIn('claim_id', \App\Models\Claim::where('student_id', $user->id)->pluck('id'))->delete();
        \App\Models\Claim::where('student_id', $user->id)->delete();
        \App\Models\AiMatch::whereIn('report_id', \App\Models\LostItemReport::where('user_id', $user->id)->pluck('id'))->delete();
        \App\Models\LostItemReport::where('user_id', $user->id)->delete();
        \App\Models\Notification::where('user_id', $user->id)->delete();
        \App\Models\SupportReply::where('user_id', $user->id)->delete();
        \App\Models\SupportMessage::where('user_id', $user->id)->delete();

        $user->delete();

        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => 'Student Account Permanently Deleted',
            'target_type' => 'users',
            'target_id' => $id,
            'details' => 'Admin permanently deleted revoked student account: ' . $name,
            'performed_by' => 'Admin: ' . $request->user()->name,
            'ip_address' => $request->ip(),
        ]);

        return response()->json(['message' => 'Student account permanently deleted.']);
    }

    public function adjustTrustScore(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'points' => 'required|integer|min:-100|max:100',
            'reason' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $user = User::findOrFail($id);
        if ($user->role !== 'student') {
            return response()->json(['message' => 'Trust score only applies to student accounts.'], 403);
        }

        $trustService = new \App\Services\TrustScoreService();
        $trustService->adjustScore($user, (int) $request->points, 'Manual adjustment by Super Admin: ' . $request->reason);

        return response()->json(['message' => 'Trust score updated successfully', 'user' => $user->fresh()]);
    }

}
