<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\LostItemReport;
use App\Models\FoundItemRecord;
use App\Models\AiMatch;
use App\Models\Claim;
use App\Models\Notification;
use App\Models\AuditLog;
use App\Models\LocationLog;
use App\Models\SystemSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class SystemStatsController extends Controller
{
    public function index()
    {
        $totalRecords = User::count()
            + LostItemReport::count()
            + FoundItemRecord::count()
            + AiMatch::count()
            + Claim::count()
            + Notification::count()
            + AuditLog::count()
            + LocationLog::count();

        $sizeResult = DB::selectOne("SELECT pg_database_size(current_database()) as size");
        $dbSizeGb = round($sizeResult->size / 1073741824, 2);

        return response()->json([
            'total_records' => $totalRecords,
            'db_size_gb' => $dbSizeGb,
        ]);
    }

    public function getSettings()
    {
        return response()->json([
            'match_confidence_threshold' => (int) SystemSetting::get('match_confidence_threshold', 75),
        ]);
    }

    public function updateSettings(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'match_confidence_threshold' => 'required|integer|min:1|max:100',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        SystemSetting::set('match_confidence_threshold', (string) $request->match_confidence_threshold);

        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => 'System Setting Updated',
            'target_type' => 'system_settings',
            'target_id' => 0,
            'details' => 'Match confidence threshold changed to ' . $request->match_confidence_threshold . '%',
            'performed_by' => 'Super Admin: ' . $request->user()->name,
            'ip_address' => $request->ip(),
        ]);

        return response()->json([
            'message' => 'Settings updated successfully',
            'match_confidence_threshold' => (int) $request->match_confidence_threshold,
        ]);
    }

    public function backupNow(Request $request)
    {
        $backup = [
            'backup_created_at' => now()->toIso8601String(),
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

        $filename = 'findnest_backup_' . now()->format('Y-m-d_His') . '.json';
        $path = 'backups/' . $filename;

        \Illuminate\Support\Facades\Storage::disk('local')->put($path, json_encode($backup, JSON_PRETTY_PRINT));

        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => 'Database Backup Created',
            'target_type' => 'system',
            'target_id' => 0,
            'details' => 'Manual backup created: ' . $filename,
            'performed_by' => 'Super Admin: ' . $request->user()->name,
            'ip_address' => $request->ip(),
        ]);

        return response()->json([
            'message' => 'Backup completed successfully',
            'filename' => $filename,
            'created_at' => now()->toIso8601String(),
            'record_count' => [
                'users' => count($backup['users']),
                'lost_item_reports' => count($backup['lost_item_reports']),
                'found_item_records' => count($backup['found_item_records']),
                'ai_matches' => count($backup['ai_matches']),
                'claims' => count($backup['claims']),
            ],
        ]);
    }

    public function listBackups()
    {
        $files = \Illuminate\Support\Facades\Storage::disk('local')->files('backups');
        $backups = collect($files)->map(function ($file) {
            return [
                'filename' => basename($file),
                'size_kb' => round(\Illuminate\Support\Facades\Storage::disk('local')->size($file) / 1024, 1),
                'created_at' => \Illuminate\Support\Facades\Storage::disk('local')->lastModified($file),
            ];
        })->sortByDesc('created_at')->values();

        return response()->json(['backups' => $backups]);
    }

    public function downloadBackup(Request $request, $filename)
    {
        $path = 'backups/' . $filename;
        if (!\Illuminate\Support\Facades\Storage::disk('local')->exists($path)) {
            return response()->json(['message' => 'Backup file not found'], 404);
        }
        $fullPath = \Illuminate\Support\Facades\Storage::disk('local')->path($path);
        return response()->download($fullPath, $filename);
    }

    public function getMaintenanceMode()
    {
        return response()->json([
            'maintenance_mode' => SystemSetting::get('maintenance_mode', '0') === '1',
        ]);
    }

    public function toggleMaintenanceMode(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'maintenance_mode' => 'required|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        SystemSetting::set('maintenance_mode', $request->boolean('maintenance_mode') ? '1' : '0');

        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => $request->boolean('maintenance_mode') ? 'Maintenance Mode Enabled' : 'Maintenance Mode Disabled',
            'target_type' => 'system_settings',
            'target_id' => 0,
            'details' => 'System maintenance mode ' . ($request->boolean('maintenance_mode') ? 'enabled' : 'disabled'),
            'performed_by' => 'Super Admin: ' . $request->user()->name,
            'ip_address' => $request->ip(),
        ]);

        return response()->json([
            'message' => 'Maintenance mode updated',
            'maintenance_mode' => $request->boolean('maintenance_mode'),
        ]);
    }
}
