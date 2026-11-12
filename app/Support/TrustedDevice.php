<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// "Remember me": a phone that passed the email code once can skip it for 30 days
class TrustedDevice
{
    // Creates a token for this student's phone. Only its hash is saved
    public static function issue(User $user): string
    {
        $token = Str::random(64);

        DB::table('trusted_devices')->insert([
            'user_id' => $user->id,
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addDays(30),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Keep at most 5 remembered phones per student
        $old = DB::table('trusted_devices')->where('user_id', $user->id)->orderByDesc('id')->skip(5)->pluck('id');
        if ($old->isNotEmpty()) {
            DB::table('trusted_devices')->whereIn('id', $old)->delete();
        }

        return $token;
    }

    // True when this token belongs to this student and has not expired. Using it renews the 30 days
    public static function valid(User $user, ?string $token): bool
    {
        if (!$token) return false;

        $row = DB::table('trusted_devices')
            ->where('user_id', $user->id)
            ->where('token_hash', hash('sha256', $token))
            ->where('expires_at', '>', now())
            ->first();

        if (!$row) return false;

        DB::table('trusted_devices')->where('id', $row->id)->update([
            'last_used_at' => now(),
            'expires_at' => now()->addDays(30),
        ]);

        return true;
    }

    public static function forget(User $user): void
    {
        DB::table('trusted_devices')->where('user_id', $user->id)->delete();
    }
}
