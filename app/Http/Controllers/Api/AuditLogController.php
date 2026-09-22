<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\Request;

class AuditLogController extends Controller
{
    public function index(Request $request)
    {
        $logs = AuditLog::with('user')
            ->when($request->action, fn($q) => $q->where('action', 'like', '%' . $request->action . '%'))
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
