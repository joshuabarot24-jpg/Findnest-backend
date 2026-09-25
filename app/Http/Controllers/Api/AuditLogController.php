<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\Request;

class AuditLogController extends Controller
{
    protected $routineActions = [
        'Student Login',
        'Admin Login',
        'Super Admin Login',
        'User Created',
        'User Updated',
    ];

    public function index(Request $request)
    {
        $logs = AuditLog::with('user')
            ->whereNotIn('action', $this->routineActions)
            ->when($request->action, function ($q) use ($request) {
                $term = $request->action;
                $q->where(function ($sub) use ($term) {
                    $sub->where('action', 'ilike', '%' . $term . '%')
                        ->orWhere('details', 'ilike', '%' . $term . '%')
                        ->orWhere('performed_by', 'ilike', '%' . $term . '%')
                        ->orWhereRaw("CONCAT('REC-', LPAD(id::text, 3, '0')) ilike ?", ['%' . $term . '%']);
                });
            })
            ->when($request->user_id, fn($q) => $q->where('user_id', $request->user_id))
            ->orderBy('created_at', 'desc')
            ->paginate(50);

        return response()->json(['logs' => $logs]);
    }

    public function byCase(Request $request, $type, $id)
    {
        $logs = AuditLog::where('target_type', $type)
            ->where('target_id', $id)
            ->orderBy('created_at', 'asc')
            ->get();

        return response()->json(['logs' => $logs]);
    }
}
