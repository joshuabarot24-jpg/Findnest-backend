<?php
namespace App\Services;

use Kreait\Firebase\Factory;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\Notification;

class FcmService
{
    protected $messaging = null;

    protected function messaging()
    {
        if ($this->messaging) {
            return $this->messaging;
        }

        $path = config('firebase.projects.' . config('firebase.default') . '.credentials')
            ?: env('FIREBASE_CREDENTIALS');
        if (!str_starts_with($path, '/')) {
            $path = base_path($path);
        }

        $this->messaging = (new Factory)->withServiceAccount($path)->createMessaging();
        return $this->messaging;
    }

    public function sendToUser(string $fcmToken, string $title, string $body, array $data = []): void
    {
        try {
            $message = CloudMessage::new()
                ->toToken($fcmToken)
                ->withNotification(Notification::create($title, $body))
                ->withData($data);

            $this->messaging()->send($message);
        } catch (\Throwable $e) {
            \Log::error('FCM Error: ' . $e->getMessage());
        }
    }
}
