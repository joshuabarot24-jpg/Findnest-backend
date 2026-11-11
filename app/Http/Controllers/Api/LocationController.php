<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LocationLog;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LocationController extends Controller
{
    private const TZ = 'Asia/Manila';

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

    // Top 3 lost and top 3 found locations, for the selected period
    public function topLocations(Request $request)
    {
        $window = $this->dateWindow($request);

        return response()->json([
            'lost' => $this->topFrom('lost_item_reports', 'location_lost', $window, []),
            'found' => $this->topFrom('found_item_records', 'location_found', $window, ['confiscated', 'others']),
        ]);
    }

    // Lost and found counts per location, for the grouped bar chart
    public function activity(Request $request)
    {
        $window = $this->dateWindow($request);
        $type = in_array($request->input('type'), ['lost', 'found'], true) ? $request->input('type') : 'both';

        $lost = $type === 'found' ? [] : $this->countAll('lost_item_reports', 'location_lost', $window, []);
        $found = $type === 'lost' ? [] : $this->countAll('found_item_records', 'location_found', $window, ['confiscated', 'others']);

        $merged = [];
        foreach ($lost as $norm => $row) {
            $merged[$norm] = ['location' => $row['label'], 'lost' => $row['count'], 'found' => 0];
        }
        foreach ($found as $norm => $row) {
            if (!isset($merged[$norm])) {
                $merged[$norm] = ['location' => $row['label'], 'lost' => 0, 'found' => 0];
            }
            $merged[$norm]['found'] = $row['count'];
        }

        $rows = array_values($merged);
        usort($rows, function ($a, $b) {
            return (($b['lost'] + $b['found']) <=> ($a['lost'] + $a['found'])) ?: strcmp($a['location'], $b['location']);
        });

        return response()->json(['rows' => array_slice($rows, 0, 8)]);
    }

    // Turns year, month, range or custom dates into a start and end in Manila time. Null means all time
    private function dateWindow(Request $request): ?array
    {
        $now = Carbon::now(self::TZ);
        $range = $request->input('range');

        try {
            if ($range === 'custom' && $request->filled('start_date') && $request->filled('end_date')) {
                $start = Carbon::parse($request->input('start_date'), self::TZ)->startOfDay();
                $end = Carbon::parse($request->input('end_date'), self::TZ)->endOfDay();
                if ($end->lt($start)) {
                    [$start, $end] = [$end->copy()->startOfDay(), $start->copy()->endOfDay()];
                }
                return $this->clampWindow($start, $end);
            }

            if ($range === 'week') {
                return [$now->copy()->startOfWeek(), $now->copy()->endOfWeek()];
            }
            if ($range === 'month') {
                return [$now->copy()->startOfMonth(), $now->copy()->endOfMonth()];
            }

            $year = (int) $request->input('year', 0);
            $month = (int) $request->input('month', 0);
            $hasMonth = $month >= 1 && $month <= 12;

            if ($year >= 2000 && $year <= 2100) {
                $start = Carbon::create($year, $hasMonth ? $month : 1, 1, 0, 0, 0, self::TZ);
                $end = $hasMonth ? $start->copy()->endOfMonth() : $start->copy()->endOfYear();
                return $this->clampWindow($start, $end);
            }

            // A month with no year means that month of the current year
            if ($hasMonth) {
                $start = Carbon::create($now->year, $month, 1, 0, 0, 0, self::TZ);
                return $this->clampWindow($start, $start->copy()->endOfMonth());
            }
        } catch (\Throwable $e) {
            return null;
        }

        return null;
    }

    private function baseQuery(string $table, string $column, ?array $window, array $excludeStatuses)
    {
        $q = DB::table($table)
            ->whereNotNull($column)
            ->whereRaw("TRIM($column) <> ''");

        if (!empty($excludeStatuses)) {
            $q->whereNotIn('status', $excludeStatuses);
        }

        if ($window) {
            $tz = config('app.timezone');
            $q->whereBetween('created_at', [
                $window[0]->copy()->setTimezone($tz),
                $window[1]->copy()->setTimezone($tz),
            ]);
        }

        return $q;
    }

    // Every location with its count, keyed by the cleaned-up text
    private function countAll(string $table, string $column, ?array $window, array $excludeStatuses): array
    {
        $rows = $this->baseQuery($table, $column, $window, $excludeStatuses)
            ->selectRaw("LOWER(REGEXP_REPLACE(TRIM($column), '\\s+', ' ', 'g')) as norm, MIN(TRIM($column)) as label, COUNT(*) as count")
            ->groupBy('norm')
            ->get();

        $out = [];
        foreach ($rows as $r) {
            $out[$r->norm] = ['label' => $r->label, 'count' => (int) $r->count];
        }
        return $out;
    }

    // Reports only exist from 2025 until today, so a period is limited to that range
    private function clampWindow(Carbon $start, Carbon $end): array
    {
        $min = Carbon::create(2025, 1, 1, 0, 0, 0, self::TZ);
        $max = Carbon::now(self::TZ)->endOfDay();
        if ($start->lt($min)) $start = $min->copy();
        if ($end->gt($max)) $end = $max->copy();
        if ($start->gt($end)) $start = $end->copy()->startOfDay();
        return [$start, $end];
    }

    private function topFrom(string $table, string $column, ?array $window, array $excludeStatuses): array
    {
        $base = $this->baseQuery($table, $column, $window, $excludeStatuses);

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
