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

        public function topLocations()
    {
        return response()->json([
            'lost' => $this->topFrom('lost_item_reports', 'location_lost'),
            'found' => $this->topFrom('found_item_records', 'location_found'),
        ]);
    }

    private function topFrom(string $table, string $column): array
    {
        $base = DB::table($table)
            ->whereNotNull($column)
            ->whereRaw("TRIM($column) <> ''");

        $total = (clone $base)->count();

        $rows = (clone $base)
            ->selectRaw("LOWER(REGEXP_REPLACE(TRIM($column), '\\s+', ' ', 'g')) as norm, MIN(TRIM($column)) as label, COUNT(*) as count")
            ->groupBy('norm')
            ->orderByDesc('count')
            ->orderBy('norm')
            ->limit(3)
            ->get();

        return [
            'total' => $total,
            'top' => $rows->map(fn($r) => [
                'location' => $r->label,
                'count' => (int) $r->count,
                'percent' => $total > 0 ? (int) round(($r->count / $total) * 100) : 0,
            ])->values(),
        ];
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
