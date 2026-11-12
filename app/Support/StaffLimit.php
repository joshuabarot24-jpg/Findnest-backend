<?php

namespace App\Support;

use App\Models\User;

// At most 2 active admins and 1 active super admin at a time
class StaffLimit
{
    public static function max(string $role): int
    {
        return $role === 'super_admin' ? 1 : 2;
    }

    public static function full(string $role, ?int $exceptId = null): bool
    {
        return User::where('role', $role)
            ->where('is_active', true)
            ->when($exceptId, fn ($q) => $q->where('id', '!=', $exceptId))
            ->count() >= self::max($role);
    }

    public static function message(string $role): string
    {
        return $role === 'super_admin'
            ? 'Only 1 active Super Admin is allowed.'
            : 'Only ' . self::max($role) . ' active Admins are allowed. Archive one first.';
    }
}
