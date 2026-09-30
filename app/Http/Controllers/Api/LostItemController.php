<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LostItemReport;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use App\Services\MatchScoreService;

class LostItemController extends Controller
{
    public function index(Request $request)
    {
        $reports = LostItemReport::with('user')
            ->when($request->status, fn($q) => $q->where('status', $request->status))
            ->when($request->category, fn($q) => $q->where('category', $request->category))
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json(['reports' => $reports]);
    }

    public function myReports(Request $request)
    {
        $reports = LostItemReport::where('user_id', $request->user()->id)
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json(['reports' => $reports]);
    }

    public function store(Request $request)
    {
        $isAdmin = in_array($request->user()->role, ['admin', 'super_admin']);

        if (!$isAdmin) {
            $activeCount = LostItemReport::where('user_id', $request->user()->id)
                ->where('status', 'searching')
                ->count();

            if ($activeCount >= 5) {
                return response()->json([
                    'message' => 'You have reached the limit of 5 active lost item reports. Please wait for an existing report to be resolved before submitting a new one.',
                ], 422);
            }
        }

        $validator = Validator::make($request->all(), [
            'item_name' => 'required|string|max:255',
            'category' => 'required|string',
            'description' => 'nullable|string',
            'ai_description' => 'nullable|string',
            'location_lost' => 'required|string',
            'date_lost' => ['required', 'date', function ($attribute, $value, $fail) {
                $today = \Carbon\Carbon::now('Asia/Manila')->startOfDay();
                $given = \Carbon\Carbon::parse($value, 'Asia/Manila')->startOfDay();
                $daysDiff = $given->diffInDays($today, false);
                if ($given->gt($today) || $daysDiff > 2) {
                    $fail('The date must be today or within the past 2 days.');
                }
            }],
            'approx_time' => 'nullable|string',
            'primary_color' => 'nullable|string',
            'brand_model' => 'nullable|string',
            'photo_url' => 'nullable|string',
            'photo_urls' => 'nullable|array',
            'photo_urls.*' => 'string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $report = LostItemReport::create([
            'user_id' => $request->user()->id,
            'item_name' => $request->item_name,
            'category' => $request->category,
            'description' => $request->description,
            'ai_description' => $request->ai_description,
            'location_lost' => $request->location_lost,
            'date_lost' => $request->date_lost,
            'approx_time' => $request->approx_time,
            'primary_color' => $request->primary_color,
            'brand_model' => $request->brand_model,
            'photo_url' => $request->photo_url,
            'photo_urls' => $request->photo_urls ?? ($request->photo_url ? [$request->photo_url] : null),
            'status' => 'searching',
        ]);

        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => 'Lost Item Reported',
            'target_type' => 'lost_item_reports',
            'target_id' => $report->id,
            'details' => 'Student reported lost item: ' . $report->item_name . ' and ' . $report->location_lost,
            'performed_by' => ($isAdmin ? 'Admin' : 'Student') . ': ' . $request->user()->name,
            'ip_address' => $request->ip(),
        ]);

        $matchService = new MatchScoreService();
        $matchService->checkNewLostReport($report);

        return response()->json([
            'message' => 'Lost item report submitted successfully',
            'report' => $report
        ], 201);
    }

    public function show($id)
    {
        $report = LostItemReport::with(['user', 'aiMatches'])->findOrFail($id);
        return response()->json(['report' => $report]);
    }

    public function update(Request $request, $id)
    {
        $report = LostItemReport::findOrFail($id);

        $report->update($request->only([
            'item_name', 'category', 'description',
            'location_lost', 'date_lost', 'photo_url', 'photo_urls', 'status'
        ]));

        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => 'Lost Item Report Updated',
            'target_type' => 'lost_item_reports',
            'target_id' => $report->id,
            'details' => 'Report updated for: ' . $report->item_name,
            'performed_by' => $request->user()->name,
            'ip_address' => $request->ip(),
        ]);

        return response()->json([
            'message' => 'Report updated successfully',
            'report' => $report
        ]);
    }

    public function destroy(Request $request, $id)
    {
        $report = LostItemReport::findOrFail($id);
        $reason = $request->input('reason');

        $activeMatch = \App\Models\AiMatch::where('report_id', $report->id)
            ->whereIn('match_status', ['pending', 'confirmed'])
            ->first();

        if ($activeMatch) {
            $foundRecord = \App\Models\FoundItemRecord::find($activeMatch->found_id);
            if ($foundRecord && $foundRecord->status !== 'disposed') {
                $foundRecord->update(['status' => 'unclaimed']);
            }
            $activeMatch->update(['match_status' => 'rejected']);
        }

        $isStudent = $request->user()->role === 'student';

        $report->delete();

        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => $isStudent ? 'Lost Item Report Deleted By Student' : 'Lost Item Report Deleted',
            'target_type' => 'lost_item_reports',
            'target_id' => $id,
            'details' => $isStudent
                ? 'Student deleted their own lost report for "' . $report->item_name . '". Reason: ' . ($reason ?: 'No reason provided.')
                : 'Report deleted for: ' . $report->item_name,
            'performed_by' => ($isStudent ? 'Student: ' : '') . $request->user()->name,
            'ip_address' => $request->ip(),
        ]);

        return response()->json(['message' => 'Report deleted successfully']);
    }
}
