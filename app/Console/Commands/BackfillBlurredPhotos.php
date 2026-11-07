<?php
namespace App\Console\Commands;

use App\Models\LostItemReport;
use App\Support\BlurredCopy;
use Illuminate\Console\Command;

class BackfillBlurredPhotos extends Command
{
    protected $signature = 'photos:backfill-blurred';
    protected $description = 'Makes blurred copies for lost reports that do not have one';

    public function handle(): int
    {
        $count = 0;
        LostItemReport::whereNull('blurred_photo_url')->whereNotNull('photo_url')->each(function ($r) use (&$count) {
            $blurred = BlurredCopy::make($r->photo_url);
            if ($blurred) {
                $r->forceFill(['blurred_photo_url' => $blurred])->save();
                $count++;
            }
        });
        $this->info("{$count} blurred copies created.");
        return self::SUCCESS;
    }
}
