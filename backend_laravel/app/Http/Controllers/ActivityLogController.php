<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use Illuminate\Http\Request;

class ActivityLogController extends Controller
{
    public function index(Request $request)
    {
        $query = ActivityLog::with('user:id,name,role')
            ->orderByDesc('created_at');

        // Optional filters
        if ($request->filled('action')) {
            $query->where('action', $request->input('action'));
        }

        if ($request->filled('user_id')) {
            $query->where('user_id', $request->input('user_id'));
        }

        if ($request->filled('date')) {
            $query->whereDate('created_at', $request->input('date'));
        }

        // Month filter: format "YYYY-MM"
        if ($request->filled('month')) {
            $month = $request->input('month');
            $query->whereRaw("TO_CHAR(created_at, 'YYYY-MM') = ?", [$month]);
        }

        $logs = $query->paginate($request->input('per_page', 100));

        return response()->json($logs);
    }

    /**
     * GET /api/activity-logs/months
     * Returns a list of distinct months that have logs
     */
    public function months()
    {
        $months = ActivityLog::selectRaw("DISTINCT TO_CHAR(created_at, 'YYYY-MM') as month")
            ->orderByRaw("month DESC")
            ->pluck('month');

        return response()->json($months);
    }

    public function destroy(Request $request, $id)
    {
        $user = $request->user();

        if (!$user || !in_array($user->role, ['super_admin', 'gerant'])) {
            return response()->json(['message' => 'Non autorisé. Seuls Super Admin et Gérant peuvent supprimer des logs.'], 403);
        }

        $log = ActivityLog::find($id);

        if (!$log) {
            return response()->json(['message' => 'Log introuvable.'], 404);
        }

        $log->delete();

        ActivityLog::log('delete_log', "{$user->name} a supprimé un log (#{$id})", $request, $user->id);

        return response()->json(['message' => 'Log supprimé avec succès.']);
    }

    public function bulkDestroy(Request $request)
    {
        $user = $request->user();

        if (!$user || !in_array($user->role, ['super_admin', 'gerant'])) {
            return response()->json(['message' => 'Non autorisé.'], 403);
        }

        $ids = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
        ])['ids'];

        $count = ActivityLog::whereIn('id', $ids)->delete();

        ActivityLog::log('delete_log', "{$user->name} a supprimé {$count} log(s)", $request, $user->id);

        return response()->json(['message' => "{$count} log(s) supprimé(s).", 'count' => $count]);
    }
}
