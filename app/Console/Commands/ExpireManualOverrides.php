<?php
namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Console\Command;

class ExpireManualOverrides extends Command
{
    protected $signature = 'overrides:expire';
    protected $description = 'Turns off manual AI overrides that passed their 24 hour limit';

    public function handle(): int
    {
        $expired = User::where('manual_override_granted', true)
            ->where(function ($q) {
                $q->whereNull('override_expires_at')->orWhere('override_expires_at', '<=', now());
            })
            ->get();

        foreach ($expired as $student) {
            $student->forceFill(['manual_override_granted' => false, 'override_expires_at' => null])->save();

            AuditLog::create([
                'user_id' => $student->id,
                'action' => 'Manual Override Auto-Expired',
                'target_type' => 'users',
                'target_id' => $student->id,
                'details' => 'Manual AI override for ' . $student->name . ' expired after 24 hours',
                'performed_by' => 'System: Override Expiry',
                'ip_address' => 'system',
            ]);
        }

        $this->info($expired->count() . ' override(s) expired.');
        return self::SUCCESS;
    }
}
