<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AiMatch;
use App\Models\LostItemReport;
use App\Models\FoundItemRecord;
use App\Models\Notification;
use App\Models\AuditLog;
use App\Services\FcmService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Carbon\Carbon;

class AiMatchController extends Controller
{
    public function index()
    {
        $matches = AiMatch::with([
            'lostReport.user',
            'foundRecord',
            'claim'
        ])
        ->orderBy('created_at', 'desc')
        ->get();

        return response()->json(['matches' => $matches]);
    }

    public function show($id)
    {
        $match = AiMatch::with([
            'lostReport.user',
            'foundRecord',
            'claim.student'
        ])->findOrFail($id);

        return response()->json(['match' => $match]);
    }

    public function myMatches(Request $request)
        {
            $matches = AiMatch::with(['lostReport', 'claim'])
                ->whereHas('lostReport', function ($query) use ($request) {
                    $query->where('user_id', $request->user()->id);
                })
                ->whereDoesntHave('claim')
                ->where('confidence_score', '>=', 50)
                ->orderBy('created_at', 'desc')
                ->get()
                ->map(function ($match) {
                    return [
                        'id' => $match->id,
                        'confidence_score' => $match->confidence_score,
                        'match_status' => $match->match_status,
                        'matched_at' => $match->matched_at,
                        'lost_item' => [
                            'item_name' => $match->lostReport->item_name,
                            'category' => $match->lostReport->category,
                            'location_lost' => $match->lostReport->location_lost,
                        ],
                    ];
                });

            return response()->json(['matches' => $matches]);
        }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'report_id' => 'required|exists:lost_item_reports,id',
            'found_id' => 'required|exists:found_item_records,id',
            'confidence_score' => 'required|numeric|min:0|max:100',
            'attributes' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $existing = AiMatch::where('report_id', $request->report_id)
            ->where('found_id', $request->found_id)
            ->first();

        if ($existing) {
            return response()->json(['message' => 'Match already exists', 'match' => $existing], 409);
        }

        $match = AiMatch::create([
            'report_id' => $request->report_id,
            'found_id' => $request->found_id,
            'confidence_score' => $request->confidence_score,
            'attributes' => $request->attributes,
            'match_status' => 'pending',
            'matched_at' => Carbon::now(),
        ]);

        $lostReport = LostItemReport::find($request->report_id);
        $lostReport?->update(['status' => 'matched']);

        $foundRecord = FoundItemRecord::find($request->found_id);
        $foundRecord?->update(['status' => 'matched']);

        if ($lostReport) {
            Notification::create([
                'user_id' => $lostReport->user_id,
                'match_id' => $match->id,
                'title' => 'AI Match Found!',
                'message' => 'A found item matches your "' . $lostReport->item_name . '" report with ' . $request->confidence_score . '% confidence.',
                'type' => 'match',
                'is_read' => false,
                'sent_at' => Carbon::now(),
            ]);

            $student = \App\Models\User::find($lostReport->user_id);
            if ($student && $student->fcm_token) {
                $fcm = new FcmService();
                $fcm->sendToUser(
                    $student->fcm_token,
                    'AI Match Found! 🤖',
                    'A found item matches your "' . $lostReport->item_name . '" report with ' . $request->confidence_score . '% confidence.',
                    ['type' => 'ai_match', 'match_id' => (string)$match->id]
                );
            }
        }

        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => 'AI Match Triggered',
            'target_type' => 'ai_matches',
            'target_id' => $match->id,
            'details' => 'AI matched Lost Report #' . $request->report_id . ' with Found Item #' . $request->found_id . ' at ' . $request->confidence_score . '% confidence',
            'performed_by' => 'System: AI Engine',
            'ip_address' => $request->ip(),
        ]);

        return response()->json([
            'message' => 'AI match created and student notified',
            'match' => $match
        ], 201);
    }

    public function confirm(Request $request, $id)
    {
        $match = AiMatch::findOrFail($id);
        $match->update(['match_status' => 'confirmed']);

        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => 'AI Match Confirmed',
            'target_type' => 'ai_matches',
            'target_id' => $match->id,
            'details' => 'Admin confirmed AI match',
            'performed_by' => 'Admin: ' . $request->user()->name,
            'ip_address' => $request->ip(),
        ]);

        return response()->json(['message' => 'Match confirmed', 'match' => $match]);
    }

    public function reject(Request $request, $id)
    {
        $match = AiMatch::findOrFail($id);
        $match->update(['match_status' => 'rejected']);

        LostItemReport::find($match->report_id)?->update(['status' => 'searching']);
        FoundItemRecord::find($match->found_id)?->update(['status' => 'unclaimed']);

        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => 'AI Match Rejected',
            'target_type' => 'ai_matches',
            'target_id' => $match->id,
            'details' => 'Admin rejected AI match — items returned to searching/unclaimed status',
            'performed_by' => 'Admin: ' . $request->user()->name,
            'ip_address' => $request->ip(),
        ]);

        return response()->json(['message' => 'Match rejected', 'match' => $match]);
    }

    public function myMatchedItem(Request $request, $id)
    {
        $match = AiMatch::with(['lostReport', 'foundRecord'])->findOrFail($id);

        if ($match->lostReport->user_id !== $request->user()->id) {
            return response()->json(['message' => 'You are not authorized to view this match.'], 403);
        }

        if ($match->claim) {
            return response()->json(['message' => 'This match already has a claim submitted.'], 403);
        }

        return response()->json([
            'match' => [
                'id' => $match->id,
                'confidence_score' => $match->confidence_score,
                'matched_at' => $match->matched_at,
                'lost_item' => [
                    'item_name' => $match->lostReport->item_name,
                    'category' => $match->lostReport->category,
                    'location_lost' => $match->lostReport->location_lost,
                    'date_lost' => $match->lostReport->date_lost,
                ],
                'found_item' => [
                    'item_name' => $match->foundRecord->item_name,
                    'category' => $match->foundRecord->category,
                    'location_found' => $match->foundRecord->location_found,
                    'storage_location' => $match->foundRecord->storage_location,
                    'photo_url' => $match->foundRecord->photo_url,
                    'ai_description' => $match->foundRecord->ai_description,
                    'date_found' => $match->foundRecord->date_found,
                ],
            ],
        ]);
    }

}
