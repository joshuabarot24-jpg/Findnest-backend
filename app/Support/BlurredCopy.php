<?php
namespace App\Support;

use CloudinaryLabs\CloudinaryLaravel\Facades\Cloudinary;
use Illuminate\Support\Facades\Log;

class BlurredCopy
{
    // Uploads a small, heavily blurred copy as its own asset. Returns its URL, or null on failure
    public static function make(?string $url): ?string
    {
        if (!$url) {
            return null;
        }

        try {
            $result = Cloudinary::uploadApi()->upload($url, [
                'folder' => 'findnest/blurred',
                'transformation' => [
                    ['width' => 500, 'crop' => 'limit'],
                    ['effect' => 'blur:1800'],
                    ['quality' => 40],
                ],
            ]);

            return $result['secure_url'] ?? null;
        } catch (\Throwable $e) {
            Log::error('Blurred copy failed: ' . $e->getMessage());
            return null;
        }
    }
}
