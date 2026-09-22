<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Claim;
use App\Models\AiMatch;
use App\Models\LostItemReport;
use App\Models\FoundItemRecord;
use App\Models\Notification;
use App\Models\AuditLog;
use App\Models\OwnershipQuestion;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Carbon\Carbon;
use App\Services\FcmService;
use App\Services\OwnershipQuestionService;
use App\Services\TrustScoreService;

class ClaimController extends Controller
{
    public function index()
    {
        $claims = Claim::with(['student', 'admin', 'match.lostReport', 'match.foundRecord', 'ownershipQuestions'])
            ->orderByRaw('photo_similarity_score IS NULL, photo_similarity_score DESC')
            ->orderBy('created_at', 'asc')
            ->get();

        $foundIdCounts = $claims->filter(fn($c) => $c->claim_status === 'pending')
            ->groupBy(fn($c) => $c->match?->found_id)
            ->map(fn($group) => $group->count());

        $claims->each(function ($claim) use ($foundIdCounts) {
            $foundId = $claim->match?->found_id;
            $claim->competing_claims_count = $foundId ? ($foundIdCounts[$foundId] ?? 1) : 1;
        });

        return response()->json(['claims' => $claims]);
    }

    public function myClaims(Request $request)
    {
        $claims = Claim::with(['match.lostReport', 'match.foundRecord', 'ownershipQuestions'])
            ->where('student_id', $request->user()->id)
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json(['claims' => $claims]);
    }

    public function store(Request $request)
    {
        $student = $request->user();
        $trustService = new TrustScoreService();

        if ($trustService->isRestricted($student)) {
            return response()->json([
                'message' => 'Your account is currently restricted from submitting claims. Reason: ' . $student->restriction_reason,
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'match_id' => 'required|exists:ai_matches,id',
            'proof_description' => 'required|string',
            'proof_photo_url' => 'nullable|string',
            'proof_photo_urls' => 'nullable|array',
            'proof_photo_urls.*' => 'string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $existing = Claim::where('match_id', $request->match_id)
            ->where('student_id', $request->user()->id)
            ->first();

        if ($existing) {
            return response()->json(['message' => 'You have already submitted a claim for this item'], 409);
        }

        $claim = Claim::create([
            'match_id' => $request->match_id,
            'student_id' => $request->user()->id,
            'proof_description' => $request->proof_description,
            'proof_photo_url' => $request->proof_photo_url,
            'proof_photo_urls' => $request->proof_photo_urls ?? ($request->proof_photo_url ? [$request->proof_photo_url] : null),
            'claim_status' => 'pending',
        ]);

        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => 'Claim Submitted',
            'target_type' => 'claims',
            'target_id' => $claim->id,
            'details' => 'Student submitted ownership claim',
            'performed_by' => 'Student: ' . $request->user()->name,
            'ip_address' => $request->ip(),
        ]);

        if ($request->proof_photo_url) {
            $match = AiMatch::with('foundRecord')->find($request->match_id);
            $foundPhotoUrl = $match?->foundRecord?->photo_url;

            if ($foundPhotoUrl) {
                $matchService = new \App\Services\MatchScoreService();
                $photoScore = $matchService->compareClaimPhotos($request->proof_photo_url, $foundPhotoUrl);

                if ($photoScore !== null) {
                    $claim->update(['photo_similarity_score' => $photoScore]);

                    AuditLog::create([
                        'user_id' => $request->user()->id,
                        'action' => 'Claim Photo Similarity Scored',
                        'target_type' => 'claims',
                        'target_id' => $claim->id,
                        'details' => 'AI compared claimant evidence photo against found item photo: ' . $photoScore . '% similarity',
                        'performed_by' => 'System: AI Matching Engine',
                        'ip_address' => $request->ip(),
                    ]);
                }
            }
        }

        $questionService = new OwnershipQuestionService();
        $questionsGenerated = $questionService->generateQuestions($claim);

        if ($questionsGenerated) {
            AuditLog::create([
                'user_id' => $request->user()->id,
                'action' => 'Ownership Questions Generated',
                'target_type' => 'claims',
                'target_id' => $claim->id,
                'details' => 'AI generated ownership verification questions for this claim',
                'performed_by' => 'System: AI Engine',
                'ip_address' => $request->ip(),
            ]);
        }

        return response()->json([
            'message' => 'Claim submitted successfully',
            'claim' => $claim,
            'questions_generated' => $questionsGenerated,
        ], 201);
    }

        public function markCollected(Request $request, $id)
        {
        $claim = Claim::findOrFail($id);

        $claim->update(['collected_at' => Carbon::now()]);

        $match = AiMatch::find($claim->match_id);
        if ($match) {
            FoundItemRecord::find($match->found_id)?->update(['status' => 'claimed']);
        }

        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => 'Item Collected',
            'target_type' => 'claims',
            'target_id' => $claim->id,
            'details' => 'Admin confirmed item was physically collected by the student',
            'performed_by' => 'Admin: ' . $request->user()->name,
            'ip_address' => $request->ip(),
        ]);

        return response()->json(['message' => 'Item marked as collected', 'claim' => $claim]);
        }

        public function submitAppeal(Request $request, $id)
        {
            $claim = Claim::where('id', $id)
                ->where('student_id', $request->user()->id)
                ->where('claim_status', 'rejected')
                ->firstOrFail();

            $validator = Validator::make($request->all(), [
                'appeal_message' => 'required|string',
                'appeal_photo_url' => 'nullable|string',
            ]);

            if ($validator->fails()) {
                return response()->json(['errors' => $validator->errors()], 422);
            }

            $claim->update([
                'appeal_message' => $request->appeal_message,
                'appeal_photo_url' => $request->appeal_photo_url,
                'appeal_status' => 'pending',
                'appeal_submitted_at' => Carbon::now(),
            ]);

        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => 'Claim Appeal Submitted',
            'target_type' => 'claims',
            'target_id' => $claim->id,
            'details' => 'Student appealed rejected claim, escalated to super-admin review',
            'performed_by' => 'Student: ' . $request->user()->name,
            'ip_address' => $request->ip(),
        ]);

        return response()->json(['message' => 'Appeal submitted for super-admin review', 'claim' => $claim]);
    }

    public function getQuestions(Request $request, $id)
    {
        $claim = Claim::where('id', $id)
            ->where('student_id', $request->user()->id)
            ->firstOrFail();

        $questions = OwnershipQuestion::where('claim_id', $claim->id)
            ->get()
            ->map(function ($q) {
                return [
                    'id' => $q->id,
                    'question' => $q->question_text,
                    'option_a' => $q->option_a,
                    'option_b' => $q->option_b,
                    'option_c' => $q->option_c,
                    'option_d' => $q->option_d,
                    'student_answer' => $q->student_answer,
                ];
            });

        return response()->json(['questions' => $questions]);
    }

    public function submitAnswers(Request $request, $id)
    {
        $claim = Claim::where('id', $id)
            ->where('student_id', $request->user()->id)
            ->firstOrFail();

        $validator = Validator::make($request->all(), [
            'answers' => 'required|array',
            'answers.*.question_id' => 'required|exists:ownership_questions,id',
            'answers.*.answer' => 'required|string|in:a,b,c,d',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $correctCount = 0;
        $totalCount = 0;

        foreach ($request->answers as $answer) {
            $question = OwnershipQuestion::where('id', $answer['question_id'])
                ->where('claim_id', $claim->id)
                ->first();

            if (!$question) {
                continue;
            }

            $question->update(['student_answer' => strtolower($answer['answer'])]);
            $totalCount++;

            if (strtolower($answer['answer']) === strtolower($question->correct_option)) {
                $correctCount++;
            }
        }

        $passed = $totalCount > 0 && $correctCount >= 2;

        if (!$passed && $totalCount > 0) {
            $trustService = new TrustScoreService();
            $trustService->failedVerification($request->user());
        }

        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => 'Ownership Questions Answered',
            'target_type' => 'claims',
            'target_id' => $claim->id,
            'details' => 'Student scored ' . $correctCount . ' out of ' . $totalCount . ' on ownership verification. Passed: ' . ($passed ? 'Yes' : 'No'),
            'performed_by' => 'Student: ' . $request->user()->name,
            'ip_address' => $request->ip(),
        ]);

        return response()->json([
            'message' => $passed
                ? 'Verification passed. Your claim is now ready for admin review.'
                : 'Verification did not pass. Your claim will still be reviewed by an administrator.',
            'correct_count' => $correctCount,
            'total_count' => $totalCount,
            'passed' => $passed,
        ]);
    }

    public function approve(Request $request, $id)
    {
        $claim = Claim::findOrFail($id);

        $claim->update([
            'claim_status' => 'approved',
            'admin_id' => $request->user()->id,
            'admin_notes' => $request->admin_notes,
            'claimed_at' => Carbon::now(),
            'pickup_deadline' => Carbon::now()->addDays(2),
        ]);

        $match = AiMatch::find($claim->match_id);
        if ($match) {
            $match->update(['match_status' => 'confirmed']);
            LostItemReport::find($match->report_id)?->update(['status' => 'returned']);
            FoundItemRecord::find($match->found_id)?->update(['status' => 'claimed']);
        }

        $student = \App\Models\User::find($claim->student_id);
        if ($student) {
            $trustService = new TrustScoreService();
            $trustService->approvedClaim($student);

            if ($student->fcm_token) {
                $fcm = new FcmService();
                $fcm->sendToUser(
                    $student->fcm_token,
                    'Claim Approved',
                    'Your claim has been approved. Visit the Guidance Office to collect your item.',
                    ['type' => 'claim_approved', 'claim_id' => (string)$claim->id]
                );
            }
        }

        Notification::create([
            'user_id' => $claim->student_id,
            'match_id' => $claim->match_id,
            'title' => 'Claim Approved!',
            'message' => 'Your claim has been approved. Please visit the Guidance Office to collect your item.',
            'type' => 'status',
            'is_read' => false,
            'sent_at' => Carbon::now(),
        ]);

        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => 'Claim Approved',
            'target_type' => 'claims',
            'target_id' => $claim->id,
            'details' => 'Admin approved ownership claim',
            'performed_by' => 'Admin: ' . $request->user()->name,
            'ip_address' => $request->ip(),
        ]);

        return response()->json(['message' => 'Claim approved successfully', 'claim' => $claim]);
    }

    public function reject(Request $request, $id)
    {
        $claim = Claim::findOrFail($id);

        $claim->update([
            'claim_status' => 'rejected',
            'admin_id' => $request->user()->id,
            'admin_notes' => $request->admin_notes,
        ]);

        $student = \App\Models\User::find($claim->student_id);
        if ($student) {
            $trustService = new TrustScoreService();
            $trustService->rejectedClaim($student);

            if ($student->fcm_token) {
                $fcm = new FcmService();
                $fcm->sendToUser(
                    $student->fcm_token,
                    'Claim Rejected',
                    'Your claim was rejected. Reason: ' . ($request->admin_notes ?? 'Insufficient proof.'),
                    ['type' => 'claim_rejected', 'claim_id' => (string)$claim->id]
                );
            }
        }

        Notification::create([
            'user_id' => $claim->student_id,
            'match_id' => $claim->match_id,
            'title' => 'Claim Rejected',
            'message' => 'Your claim was rejected. Reason: ' . ($request->admin_notes ?? 'Insufficient proof provided.'),
            'type' => 'status',
            'is_read' => false,
            'sent_at' => Carbon::now(),
        ]);

        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => 'Claim Rejected',
            'target_type' => 'claims',
            'target_id' => $claim->id,
            'details' => 'Admin rejected ownership claim. Reason: ' . $request->admin_notes,
            'performed_by' => 'Admin: ' . $request->user()->name,
            'ip_address' => $request->ip(),
        ]);

        return response()->json(['message' => 'Claim rejected', 'claim' => $claim]);
    }

    public function pendingAppeals()
    {
        $claims = Claim::with(['student', 'match.lostReport', 'match.foundRecord'])
            ->where('appeal_status', 'pending')
            ->orderBy('appeal_submitted_at', 'asc')
            ->get();

        return response()->json(['claims' => $claims]);
    }

    public function resolveAppeal(Request $request, $id)
    {
        $claim = Claim::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'decision' => 'required|in:uphold,overturn',
            'resolution_notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        if ($request->decision === 'overturn') {
            $claim->update([
                'claim_status' => 'approved',
                'admin_notes' => $request->resolution_notes ?? $claim->admin_notes,
                'claimed_at' => Carbon::now(),
                'pickup_deadline' => Carbon::now()->addDays(2),
                'appeal_status' => 'resolved',
            ]);

            $match = AiMatch::find($claim->match_id);
            if ($match) {
                $match->update(['match_status' => 'confirmed']);
                LostItemReport::find($match->report_id)?->update(['status' => 'returned']);
                FoundItemRecord::find($match->found_id)?->update(['status' => 'claimed']);
            }

            $student = \App\Models\User::find($claim->student_id);
            if ($student) {
                $trustService = new TrustScoreService();
                $trustService->adjustScore($student, 10, 'Appeal upheld, claim overturned to approved');
            }

            Notification::create([
                'user_id' => $claim->student_id,
                'match_id' => $claim->match_id,
                'title' => 'Appeal Approved!',
                'message' => 'Your appeal was reviewed and your claim has been approved. Visit the Guidance Office to collect your item.',
                'type' => 'status',
                'is_read' => false,
                'sent_at' => Carbon::now(),
            ]);
        } else {
            $claim->update([
                'appeal_status' => 'resolved',
                'admin_notes' => $request->resolution_notes ?? $claim->admin_notes,
            ]);

            Notification::create([
                'user_id' => $claim->student_id,
                'match_id' => $claim->match_id,
                'title' => 'Appeal Reviewed',
                'message' => 'Your appeal was reviewed by the Super Admin. The original rejection has been upheld.',
                'type' => 'status',
                'is_read' => false,
                'sent_at' => Carbon::now(),
            ]);
        }

        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => 'Claim Appeal Resolved',
            'target_type' => 'claims',
            'target_id' => $claim->id,
            'details' => 'Super Admin ' . ($request->decision === 'overturn' ? 'overturned the rejection, claim approved' : 'upheld the original rejection') . '. Notes: ' . ($request->resolution_notes ?? 'None'),
            'performed_by' => 'Super Admin: ' . $request->user()->name,
            'ip_address' => $request->ip(),
        ]);

        return response()->json(['message' => 'Appeal resolved successfully', 'claim' => $claim]);
    }
}
