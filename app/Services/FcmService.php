<?php
namespace App\Services;

use Kreait\Firebase\Factory;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\Notification;

class FcmService
{
    protected $messaging;

    public function __construct()
    {
        $credentials = env('FIREBASE_CREDENTIALS');
        if ($credentials && file_exists(base_path($credentials))) {
            $factory = (new Factory)->withServiceAccount(base_path($credentials));
            $this->messaging = $factory->createMessaging();
        }
    }

    public function sendToUser(string $fcmToken, string $title, string $body, array $data = []): void
    {
        if (!$this->messaging) {
            return;
        }

        try {
            $message = CloudMessage::new()
                ->toToken($fcmToken)
                ->withNotification(Notification::create($title, $body))
                ->withData($data);

            $this->messaging->send($message);
        } catch (\Exception $e) {
            \Log::error('FCM Error: ' . $e->getMessage());
        }
    }
}
