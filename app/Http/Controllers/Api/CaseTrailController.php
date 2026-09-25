<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LostItemReport;
use App\Models\AuditLog;
use Illuminate\Http\Request;

class CaseTrailController extends Controller
{
    public function index(Request $request)
    {
        $reports = LostItemReport::with(['user', 'aiMatches.foundRecord', 'aiMatches.claim'])
            ->orderBy('created_at', 'desc')
            ->get();

        $cases = $reports->map(function ($report) {
            return $this->buildCaseSummary($report);
        });

        if ($request->search) {
            $term = strtolower($request->search);
            $cases = $cases->filter(function ($case) use ($term) {
                return str_contains(strtolower($case['item_name']), $term)
                    || str_contains(strtolower($case['category']), $term)
                    || str_contains(strtolower($case['case_id']), $term)
                    || str_contains(strtolower($case['status']), $term)
                    || str_contains(strtolower($case['reported_by'] ?? ''), $term);
            })->values();
        }

        return response()->json(['cases' => $cases]);
    }

    public function show($id)
    {
        $report = LostItemReport::with(['user', 'aiMatches.foundRecord', 'aiMatches.claim.student', 'aiMatches.claim.admin'])
            ->findOrFail($id);

        $matchIds = $report->aiMatches->pluck('id');
        $claimIds = $report->aiMatches->pluck('claim.id')->filter()->values();
        $foundIds = $report->aiMatches->pluck('foundRecord.id')->filter()->values();

        $logs = AuditLog::where(function ($q) use ($report, $matchIds, $claimIds, $foundIds) {
            $q->where(function ($q2) use ($report) {
                $q2->where('target_type', 'lost_item_reports')->where('target_id', $report->id);
            });

            if ($matchIds->isNotEmpty()) {
                $q->orWhere(function ($q2) use ($matchIds) {
                    $q2->where('target_type', 'ai_matches')->whereIn('target_id', $matchIds);
                });
            }

            if ($claimIds->isNotEmpty()) {
                $q->orWhere(function ($q2) use ($claimIds) {
                    $q2->where('target_type', 'claims')->whereIn('target_id', $claimIds);
                });
            }

            if ($foundIds->isNotEmpty()) {
                $q->orWhere(function ($q2) use ($foundIds) {
                    $q2->where('target_type', 'found_item_records')->whereIn('target_id', $foundIds);
                });
            }
        })->orderBy('created_at', 'asc')->get();

        return response()->json([
            'case' => $this->buildCaseSummary($report),
            'logs' => $logs,
        ]);
    }

    private function buildCaseSummary($report)
    {
        $latestMatch = $report->aiMatches->sortByDesc('created_at')->first();
        $claim = $latestMatch?->claim;

        $status = 'Searching';
        if ($claim) {
            if ($claim->claim_status === 'approved') {
                $status = 'Returned';
            } elseif ($claim->claim_status === 'rejected') {
                $status = 'Claim Rejected';
            } else {
                $status = 'Pending Verification';
            }
        } elseif ($latestMatch) {
            $status = 'Match Found';
        }

        return [
            'case_id' => 'CASE-' . str_pad($report->id, 3, '0', STR_PAD_LEFT),
            'report_id' => $report->id,
            'item_name' => $report->item_name,
            'category' => $report->category,
            'photo_url' => $report->photo_url ?? $latestMatch?->foundRecord?->photo_url,
            'status' => $status,
            'reported_by' => $report->user?->name,
            'created_at' => $report->created_at,
        ];
    }
}
