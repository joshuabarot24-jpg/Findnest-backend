<?php
namespace App\Services;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Mail;

class OtpMailer
{
    // Saves a new 6-digit code on the user (valid 10 minutes) and emails it
    public static function send(User $user): void
    {
        $otp = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $user->update([
            'otp_code' => $otp,
            'otp_expires_at' => Carbon::now()->addMinutes(10),
        ]);

        $emailHtml = '
        <div style="font-family: Arial, sans-serif; max-width: 400px; margin: 0 auto; padding: 30px 20px; text-align: center;">
            <p style="font-size: 20px; font-weight: 900; color: #0B4F2C; margin-bottom: 4px;">FIND<span style="color: #0B4F2C;">NEST</span></p>
            <p style="color: #9ca3af; font-size: 12px; margin-bottom: 30px;">SJDM Cornerstone College Inc.</p>
            <p style="color: #374151; font-size: 14px; margin-bottom: 20px;">Your verification code is:</p>
            <p style="font-size: 36px; font-weight: 900; letter-spacing: 8px; color: #0B4F2C; margin: 0 0 20px 0;">' . $otp . '</p>
            <p style="color: #9ca3af; font-size: 12px;">This code expires in 10 minutes.</p>
            <p style="color: #9ca3af; font-size: 11px; margin-top: 30px;">Do not share this code with anyone.</p>
        </div>';

        Mail::html($emailHtml, function ($message) use ($user) {
            $message->to($user->email)->subject('FindNest — Your Verification Code');
        });
    }
}
