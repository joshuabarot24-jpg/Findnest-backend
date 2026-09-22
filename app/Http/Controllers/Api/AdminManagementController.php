<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class AdminManagementController extends Controller
{
    public function index()
    {
        $admins = User::whereIn('role', ['admin', 'super_admin'])
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json(['admins' => $admins]);
    }

    public function show($id)
    {
        $admin = User::whereIn('role', ['admin', 'super_admin'])
            ->findOrFail($id);

        return response()->json(['admin' => $admin]);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name'     => 'required|string|max:255',
            'email'    => 'required|email|unique:users,email',
            'password' => 'required|string|min:8',
            'role'     => 'required|in:admin',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $admin = User::create([
            'name'      => $request->name,
            'email'     => $request->email,
            'password'  => Hash::make($request->password),
            'role'      => 'admin',
            'is_active' => true,
        ]);

        AuditLog::create([
            'user_id'      => $request->user()->id,
            'action'       => 'Admin Created',
            'target_type'  => 'users',
            'target_id'    => $admin->id,
            'details'      => "New admin account created for {$admin->name}",
            'performed_by' => $request->user()->name,
            'ip_address'   => $request->ip(),
        ]);

        return response()->json(['message' => 'Admin created successfully', 'admin' => $admin], 201);
    }

    public function update(Request $request, $id)
    {
        $admin = User::whereIn('role', ['admin', 'super_admin'])->findOrFail($id);

        $validator = Validator::make($request->all(), [
            'name'     => 'sometimes|string|max:255',
            'email'    => 'sometimes|email|unique:users,email,' . $id,
            'password' => 'nullable|string|min:8',
            'role'     => 'sometimes|in:admin',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $admin->name  = $request->name ?? $admin->name;
        $admin->email = $request->email ?? $admin->email;
        $admin->role  = 'admin';

        if ($request->filled('password')) {
            $admin->password = Hash::make($request->password);
        }

        $admin->save();

        AuditLog::create([
            'user_id'      => $request->user()->id,
            'action'       => 'Admin Updated',
            'target_type'  => 'users',
            'target_id'    => $admin->id,
            'details'      => "Admin account updated for {$admin->name}",
            'performed_by' => $request->user()->name,
            'ip_address'   => $request->ip(),
        ]);

        return response()->json(['message' => 'Admin updated successfully', 'admin' => $admin->fresh()]);
    }

    public function revoke(Request $request, $id)
    {
        $admin = User::whereIn('role', ['admin', 'super_admin'])->findOrFail($id);
        $admin->update(['is_active' => false]);

        AuditLog::create([
            'user_id'      => $request->user()->id,
            'action'       => 'Admin Access Revoked',
            'target_type'  => 'users',
            'target_id'    => $admin->id,
            'details'      => "Access revoked for admin {$admin->name}",
            'performed_by' => $request->user()->name,
            'ip_address'   => $request->ip(),
        ]);

        return response()->json(['message' => 'Admin access revoked successfully']);
    }

    public function restore(Request $request, $id)
    {
        $admin = User::whereIn('role', ['admin', 'super_admin'])->findOrFail($id);
        $admin->update(['is_active' => true]);

        AuditLog::create([
            'user_id'      => $request->user()->id,
            'action'       => 'Admin Access Restored',
            'target_type'  => 'users',
            'target_id'    => $admin->id,
            'details'      => "Access restored for admin {$admin->name}",
            'performed_by' => $request->user()->name,
            'ip_address'   => $request->ip(),
        ]);

        return response()->json(['message' => 'Admin access restored successfully']);
    }
}
