<?php

namespace App\Services;

use App\Models\Notification;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class StudentNotifier
{
    public static function send(?int $userId, string $title, string $message, string $type = 'status', ?int $matchId = null): void
    {
        if (!$userId) {
            return;
        }

        try {
            $duplicate = Notification::where('user_id', $userId)
                ->where('title', $title)
                ->where('message', $message)
                ->where('created_at', '>=', Carbon::now()->subMinute())
                ->exists();

            if ($duplicate) {
                return;
            }

            Notification::create([
                'user_id' => $userId,
                'match_id' => $matchId,
                'title' => $title,
                'message' => $message,
                'type' => $type,
                'is_read' => false,
                'sent_at' => Carbon::now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('StudentNotifier save failed: ' . $e->getMessage());
            return;
        }

        try {
            $user = User::find($userId);
            if ($user && $user->fcm_token) {
                (new FcmService())->sendToUser($user->fcm_token, $title, $message, ['type' => $type]);
            }
        } catch (\Throwable $e) {
            Log::warning('StudentNotifier push failed: ' . $e->getMessage());
        }
    }
}
