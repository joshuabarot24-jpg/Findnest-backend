<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FoundItemRecord;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use App\Services\MatchScoreService;

class FoundItemController extends Controller
{
    public function index(Request $request)
    {
        $lastRun = \App\Models\SystemSetting::get('last_unclaimed_check_at', null);
        if (!$lastRun || \Carbon\Carbon::parse($lastRun)->diffInMinutes(now()) >= 60) {
            \App\Models\SystemSetting::set('last_unclaimed_check_at', now()->toIso8601String());
            try {
                \Illuminate\Support\Facades\Artisan::call('items:check-unclaimed');
                \Illuminate\Support\Facades\Artisan::call('items:check-surrender-deadlines');
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::error('Unclaimed check failed: ' . $e->getMessage());
            }
        }

        $records = FoundItemRecord::with('admin')
            ->when($request->status, fn($q) => $q->where('status', $request->status))
            ->when($request->category, fn($q) => $q->where('category', $request->category))
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json(['records' => $records]);
    }

    public function store(Request $request)
    {
        if ($request->user()->role === 'student') {
            $activeCount = FoundItemRecord::where('admin_id', $request->user()->id)
                ->where('status', 'unclaimed')
                ->count();

            if ($activeCount >= 5) {
                return response()->json([
                    'message' => 'You have reached the limit of 5 active found item reports. Please wait for an existing report to be resolved before submitting a new one.',
                ], 422);
            }
        }

        $validator = Validator::make($request->all(), [
            'item_name' => 'required|string|max:255',
            'category' => 'required|string',
            'description' => 'nullable|string',
            'ai_description' => 'nullable|string',
            'location_found' => 'required|string',
            'date_found' => ['required', 'date', function ($attribute, $value, $fail) {
                $today = \Carbon\Carbon::now('Asia/Manila')->toDateString();
                if ($value !== $today) {
                    $fail('The date must be today\'s date (' . $today . ').');
                }
            }],
            'approx_time' => 'nullable|string',
            'primary_color' => 'nullable|string',
            'brand_model' => 'nullable|string',
            'photo_url' => 'nullable|string',
            'photo_urls' => 'nullable|array',
            'photo_urls.*' => 'string',
            'storage_location' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $isStudent = $request->user()->role === 'student';

        $record = FoundItemRecord::create([
            'admin_id' => $request->user()->id,
            'item_name' => $request->item_name,
            'category' => $request->category,
            'description' => $request->description,
            'ai_description' => $request->ai_description,
            'location_found' => $request->location_found,
            'receipt_confirmed' => !$isStudent,
            'receipt_confirmed_at' => !$isStudent ? now() : null,
            'surrender_deadline' => $isStudent ? now()->addDays(2) : null,
            'date_found' => $request->date_found,
            'approx_time' => $request->approx_time,
            'primary_color' => $request->primary_color,
            'brand_model' => $request->brand_model,
            'photo_url' => $request->photo_url,
            'photo_urls' => $request->photo_urls ?? ($request->photo_url ? [$request->photo_url] : null),
            'storage_location' => $request->storage_location,
            'status' => 'unclaimed',
        ]);

        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => 'Found Item Recorded',
            'target_type' => 'found_item_records',
            'target_id' => $record->id,
            'details' => 'Admin recorded found item: ' . $record->item_name . ' at ' . $record->location_found,
            'performed_by' => 'Admin: ' . $request->user()->name,
            'ip_address' => $request->ip(),
        ]);

                if ($record->receipt_confirmed) {
            $matchService = new MatchScoreService();
            $matchService->checkNewFoundRecord($record);
        }

        return response()->json([
            'message' => $record->receipt_confirmed
                ? 'Found item recorded successfully'
                : 'Found item report submitted. Please surrender it to Ms. Shelly S. Durban within 2 school days — matching will begin once receipt is confirmed.',
            'record' => $record
        ], 201);
    }

    public function show($id)
    {
        $record = FoundItemRecord::with(['admin', 'aiMatches'])->findOrFail($id);
        return response()->json(['record' => $record]);
    }

    public function update(Request $request, $id)
    {
        $record = FoundItemRecord::findOrFail($id);

        $record->update($request->only([
            'item_name', 'category', 'description',
            'location_found', 'date_found', 'photo_url', 'photo_urls',
            'storage_location', 'status'
        ]));

        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => 'Found Item Record Updated',
            'target_type' => 'found_item_records',
            'target_id' => $record->id,
            'details' => 'Record updated for: ' . $record->item_name,
            'performed_by' => $request->user()->name,
            'ip_address' => $request->ip(),
        ]);

        return response()->json([
            'message' => 'Record updated successfully',
            'record' => $record
        ]);
    }

    public function destroy(Request $request, $id)
    {
        $record = FoundItemRecord::findOrFail($id);
        $record->delete();

        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => 'Found Item Record Deleted',
            'target_type' => 'found_item_records',
            'target_id' => $id,
            'details' => 'Record deleted for: ' . $record->item_name,
            'performed_by' => $request->user()->name,
            'ip_address' => $request->ip(),
        ]);

        return response()->json(['message' => 'Record deleted successfully']);
    }

    public function documentDisposal(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'disposal_notes' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $record = FoundItemRecord::findOrFail($id);
        $record->update([
            'status' => 'disposed',
            'disposal_notes' => $request->disposal_notes,
            'disposed_at' => now(),
        ]);

        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => 'Item Disposal Documented',
            'target_type' => 'found_item_records',
            'target_id' => $record->id,
            'details' => 'Admin documented disposal of "' . $record->item_name . '": ' . $request->disposal_notes,
            'performed_by' => 'Admin: ' . $request->user()->name,
            'ip_address' => $request->ip(),
        ]);

        return response()->json(['message' => 'Disposal documented successfully', 'record' => $record]);
    }

    public function confirmReceipt(Request $request, $id)
    {
        $record = FoundItemRecord::findOrFail($id);

        if ($record->receipt_confirmed) {
            return response()->json(['message' => 'Receipt was already confirmed for this item.'], 422);
        }

        $record->update([
            'receipt_confirmed' => true,
            'receipt_confirmed_at' => now(),
            'surrender_deadline' => null,
        ]);

        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => 'Item Receipt Confirmed',
            'target_type' => 'found_item_records',
            'target_id' => $record->id,
            'details' => 'Admin confirmed physical receipt of "' . $record->item_name . '", AI matching now active',
            'performed_by' => 'Admin: ' . $request->user()->name,
            'ip_address' => $request->ip(),
        ]);

        $matchService = new MatchScoreService();
        $matchService->checkNewFoundRecord($record);

        return response()->json(['message' => 'Receipt confirmed, item is now active and matched against lost reports.', 'record' => $record]);
    }
}
