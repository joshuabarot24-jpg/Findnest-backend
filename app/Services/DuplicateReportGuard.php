<?php
namespace App\Services;

use App\Models\FoundItemRecord;
use App\Models\LostItemReport;
use Carbon\Carbon;

class DuplicateReportGuard
{
    private const CROSS_TYPE_WINDOW_MINUTES = 15;

    // New lost report: duplicate of an active lost report of the same owner
    public function lostDuplicate(int $ownerId, array $item, bool $checkRecentFound): bool
    {
        $active = LostItemReport::where('user_id', $ownerId)
            ->where('status', 'searching')
            ->get(['item_name', 'category', 'primary_color', 'photo_url']);

        if ($this->anyMatch($active, $item)) {
            return true;
        }

        if ($checkRecentFound) {
            $recent = FoundItemRecord::where('admin_id', $ownerId)
                ->where('created_at', '>=', Carbon::now()->subMinutes(self::CROSS_TYPE_WINDOW_MINUTES))
                ->whereNotIn('status', ['confiscated', 'others'])
                ->get(['item_name', 'category', 'primary_color', 'photo_url']);

            return $this->anyMatch($recent, $item);
        }

        return false;
    }

    // New found item: duplicate of an active found item logged by the same account
    public function foundDuplicate(int $userId, array $item, bool $checkRecentLost): bool
    {
        $active = FoundItemRecord::where('admin_id', $userId)
            ->whereIn('status', ['unclaimed', 'waiting_for_surrender'])
            ->get(['item_name', 'category', 'primary_color', 'photo_url']);

        if ($this->anyMatch($active, $item)) {
            return true;
        }

        if ($checkRecentLost) {
            $recent = LostItemReport::where('user_id', $userId)
                ->where('created_at', '>=', Carbon::now()->subMinutes(self::CROSS_TYPE_WINDOW_MINUTES))
                ->get(['item_name', 'category', 'primary_color', 'photo_url']);

            return $this->anyMatch($recent, $item);
        }

        return false;
    }

    private function anyMatch($records, array $item): bool
    {
        $mine = $this->normalize($item['item_name'] ?? null);
        $myColor = $this->normalize($item['primary_color'] ?? null);

        foreach ($records as $r) {
            if (!empty($item['photo_url']) && $r->photo_url === $item['photo_url']) {
                return true;
            }
            if ($r->category !== ($item['category'] ?? null)) {
                continue;
            }
            $theirColor = $this->normalize($r->primary_color);
            if ($myColor !== '' && $theirColor !== '' && $myColor !== $theirColor) {
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
    private function normalize(?string $text): string
    {
        $clean = strtolower(preg_replace('/[^a-z0-9]+/i', ' ', (string) $text));
        $words = array_values(array_filter(explode(' ', trim($clean))));
        sort($words);
        return implode(' ', $words);
    }
}
