<?php
namespace App\Services;

use App\Models\FoundItemRecord;
use App\Models\LostItemReport;
use Carbon\Carbon;

class DuplicateReportGuard
{
    private const WINDOW_MINUTES = 15;

    // True if this student already logged a matching report (lost or found) in the last 15 minutes
    public function exists(int $userId, ?string $itemName, ?string $category, ?string $photoUrl): bool
    {
        $since = Carbon::now()->subMinutes(self::WINDOW_MINUTES);

        $lost = LostItemReport::where('user_id', $userId)
            ->where('created_at', '>=', $since)
            ->get(['item_name', 'category', 'photo_url']);

        $found = FoundItemRecord::where('admin_id', $userId)
            ->where('created_at', '>=', $since)
            ->get(['item_name', 'category', 'photo_url']);

        $mine = $this->normalize($itemName);

        foreach ($lost->concat($found) as $r) {
            if ($photoUrl && $r->photo_url === $photoUrl) {
                return true;
            }
            if ($r->category !== $category) {
                continue;
            }
            similar_text($mine, $this->normalize($r->item_name), $percent);
            if ($percent >= 80) {
                return true;
            }
        }

        return false;
    }

    // Lowercase, letters and numbers only, words sorted, so word order does not matter
    private function normalize(?string $name): string
    {
        $clean = strtolower(preg_replace('/[^a-z0-9]+/i', ' ', (string) $name));
        $words = array_values(array_filter(explode(' ', trim($clean))));
        sort($words);
        return implode(' ', $words);
    }
}
