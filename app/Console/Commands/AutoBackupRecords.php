<?php
namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\User;
use App\Models\LostItemReport;
use App\Models\FoundItemRecord;
use App\Models\AiMatch;
use App\Models\Claim;
use App\Models\Notification;
use App\Models\AuditLog;
use App\Models\LocationLog;
use App\Models\SystemSetting;
use Illuminate\Support\Facades\Storage;
use Carbon\Carbon;

class AutoBackupRecords extends Command
{
    protected $signature = 'records:auto-backup';
    protected $description = 'Automatically backs up all records every 30 days and flags a cleanup prompt for Super Admin';

    public function handle()
    {
        $lastBackup = SystemSetting::get('last_auto_backup_at', null);

        if ($lastBackup && Carbon::parse($lastBackup)->diffInDays(now()) < 30) {
            $this->info('Auto-backup not due yet. Last backup: ' . $lastBackup);
            return;
        }

        $backup = [
            'backup_created_at' => now()->toIso8601String(),
            'backup_type' => 'automatic',
            'users' => User::all()->toArray(),
            'lost_item_reports' => LostItemReport::all()->toArray(),
            'found_item_records' => FoundItemRecord::all()->toArray(),
            'ai_matches' => AiMatch::all()->toArray(),
            'claims' => Claim::all()->toArray(),
            'notifications' => Notification::all()->toArray(),
            'audit_logs' => AuditLog::all()->toArray(),
            'location_logs' => LocationLog::all()->toArray(),
            'system_settings' => SystemSetting::all()->toArray(),
        ];

        $filename = 'findnest_autobackup_' . now()->format('Y-m-d_His') . '.json';
        Storage::disk('local')->put('backups/' . $filename, json_encode($backup, JSON_PRETTY_PRINT));

        SystemSetting::set('last_auto_backup_at', now()->toIso8601String());
        SystemSetting::set('pending_cleanup_prompt', '1');
        SystemSetting::set('pending_cleanup_filename', $filename);

        AuditLog::create([
            'user_id' => null,
            'action' => 'Automatic Backup Created',
            'target_type' => 'system',
            'target_id' => 0,
            'details' => '30-day automatic backup created: ' . $filename,
            'performed_by' => 'System: Scheduled Backup',
            'ip_address' => 'system',
        ]);

        $this->info('Auto-backup completed: ' . $filename);
    }
}
