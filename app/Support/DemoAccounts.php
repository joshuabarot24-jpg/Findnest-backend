<?php

namespace App\Support;

// REMOVE when the demo accounts are deleted: these two accounts skip the email code at login
class DemoAccounts
{
    private const OTP_EXEMPT = ['superadmin@findnest.com', 'admin@findnest.com', 'student1@findnest.com', 'student2@findnest.com'];

    public static function skipsOtp(string $email): bool
    {
        return in_array(strtolower(trim($email)), self::OTP_EXEMPT, true);
    }
}
