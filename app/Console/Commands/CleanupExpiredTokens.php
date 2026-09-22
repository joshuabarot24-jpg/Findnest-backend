<?php
namespace App\Console\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Console\Command\Command as SymfonyCommand;
use Laravel\Sanctum\PersonalAccessToken;
use Carbon\Carbon;

class CleanupExpiredTokens extends Command
{
    protected $signature = 'tokens:cleanup';
    protected $description = 'Delete expired personal access tokens (older than 8 hours)';

    public function handle()
    {
        $cutoff = Carbon::now()->subMinutes(480);

        $deleted = PersonalAccessToken::where('created_at', '<', $cutoff)->delete();

        $this->info("Deleted {$deleted} expired token(s).");

        return SymfonyCommand::SUCCESS;
    }
}
