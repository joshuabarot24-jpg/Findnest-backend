<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LocationLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LocationController extends Controller
{
    public function index(Request $request)
    {
        $logs = LocationLog::with('lostReport')
            ->when($request->type, fn($q) => $q->where('type', $request->type))
            ->get();

        return response()->json(['locations' => $logs]);
    }

    public function hotspots()
    {
        $hotspots = LocationLog::select('area', 'building', 'type', DB::raw('count(*) as count'))
            ->groupBy('area', 'building', 'type')
            ->orderByDesc('count')
            ->get();

        return response()->json(['hotspots' => $hotspots]);
    }

    public function store(Request $request)
    {
        $log = LocationLog::create([
            'report_id' => $request->report_id,
            'building' => $request->building,
            'area' => $request->area,
            'latitude' => $request->latitude,
            'longitude' => $request->longitude,
            'type' => $request->type,
        ]);

        return response()->json(['message' => 'Location logged', 'log' => $log], 201);
    }
}
