<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Carbon\Carbon;

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
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:8',
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
            'email' => 'required|email|unique:users,email,' . $id,
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

        if ($request->password && $user->role !== 'student') {
            $user->update(['password' => Hash::make($request->password)]);
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
        $user->update(['is_active' => false]);

        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => 'User Revoked',
            'target_type' => 'users',
            'target_id' => $user->id,
            'details' => 'User access revoked: ' . $user->name,
            'performed_by' => 'Super Admin: ' . $request->user()->name,
            'ip_address' => $request->ip(),
        ]);

        return response()->json(['message' => 'User revoked successfully', 'user' => $user]);
    }

    public function restore(Request $request, $id)
    {
        $user = User::findOrFail($id);
        $user->update(['is_active' => true]);

        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => 'User Restored',
            'target_type' => 'users',
            'target_id' => $user->id,
            'details' => 'User access restored: ' . $user->name,
            'performed_by' => 'Super Admin: ' . $request->user()->name,
            'ip_address' => $request->ip(),
        ]);

        return response()->json(['message' => 'User restored successfully', 'user' => $user]);
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

}
