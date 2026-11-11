<?php

namespace App\Support;

use App\Models\User;

// At most 2 active admins and 2 active super admins at a time
class StaffLimit
{
    public const MAX = 2;

    public static function full(string $role, ?int $exceptId = null): bool
    {
        return User::where('role', $role)
            ->where('is_active', true)
            ->when($exceptId, fn ($q) => $q->where('id', '!=', $exceptId))
            ->count() >= self::MAX;
    }

    public static function message(string $role): string
    {
        $label = $role === 'super_admin' ? 'Super Admins' : 'Admins';
        return 'Only ' . self::MAX . ' active ' . $label . ' are allowed. Archive one first.';
    }
}
