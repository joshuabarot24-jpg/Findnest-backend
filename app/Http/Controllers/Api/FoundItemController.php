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
        $records = FoundItemRecord::with('admin')
            ->when($request->status, fn($q) => $q->where('status', $request->status))
            ->when($request->category, fn($q) => $q->where('category', $request->category))
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json(['records' => $records]);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'item_name' => 'required|string|max:255',
            'category' => 'required|string',
            'description' => 'nullable|string',
            'ai_description' => 'nullable|string',
            'location_found' => 'required|string',
            'date_found' => 'required|date',
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

        $record = FoundItemRecord::create([
            'admin_id' => $request->user()->id,
            'item_name' => $request->item_name,
            'category' => $request->category,
            'description' => $request->description,
            'ai_description' => $request->ai_description,
            'location_found' => $request->location_found,
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

        $matchService = new MatchScoreService();
        $matchService->checkNewFoundRecord($record);

        return response()->json([
            'message' => 'Found item recorded successfully',
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
}
