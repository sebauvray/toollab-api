<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Erreurs serveur enregistrées par App\Support\ErrorRecorder (super-admin). */
class ErrorLogController extends Controller
{
    private const COLUMNS = [
        'id', 'category', 'exception_class', 'message', 'file', 'line', 'context',
        'occurrences', 'first_seen_at', 'last_seen_at', 'resolved_at',
    ];

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => 'nullable|in:open,resolved,all',
            'category' => 'nullable|in:http,job,mail,console',
            'page' => 'nullable|integer|min:1',
        ]);

        $query = DB::table('error_groups')->select(self::COLUMNS)->orderByDesc('last_seen_at');

        match ($validated['status'] ?? 'open') {
            'open' => $query->whereNull('resolved_at'),
            'resolved' => $query->whereNotNull('resolved_at'),
            default => null,
        };
        if (! empty($validated['category'])) {
            $query->where('category', $validated['category']);
        }

        $page = $query->paginate(30);
        $last24h = DB::table('error_events')
            ->whereIn('error_group_id', $page->getCollection()->pluck('id'))
            ->where('created_at', '>=', now()->subDay())
            ->groupBy('error_group_id')
            ->selectRaw('error_group_id, COUNT(*) as total')
            ->pluck('total', 'error_group_id');

        $page->getCollection()->transform(fn ($g) => [...(array) $g, 'last_24h' => (int) ($last24h[$g->id] ?? 0)]);

        return response()->json($page);
    }

    public function show(int $id): JsonResponse
    {
        $group = DB::table('error_groups')->find($id);
        abort_unless($group, 404);

        $school = $group->last_school_id ? DB::table('schools')->where('id', $group->last_school_id)->value('name') : null;
        $user = $group->last_user_id ? DB::table('users')->where('id', $group->last_user_id)->first(['id', 'first_name', 'last_name', 'email']) : null;

        return response()->json([
            ...(array) $group,
            'last_school' => $school,
            'last_user' => $user,
            'hourly' => self::hourly($id),
        ]);
    }

    public function resolve(int $id): JsonResponse
    {
        abort_unless(DB::table('error_groups')->where('id', $id)->update(['resolved_at' => now()]), 404);

        return response()->json(['id' => $id, 'resolved_at' => now()]);
    }

    public function reopen(int $id): JsonResponse
    {
        abort_unless(DB::table('error_groups')->where('id', $id)->update(['resolved_at' => null]), 404);

        return response()->json(['id' => $id, 'resolved_at' => null]);
    }

    /** Résumé pour le tableau de bord : volume 24 h, erreurs ouvertes, e-mails en échec. */
    /** En-tête de la page /admin/errors. */
    public function summaryJson(): \Illuminate\Http\JsonResponse
    {
        return response()->json(self::summary());
    }

    public static function summary(): array
    {
        return [
            'last_24h' => DB::table('error_events')->where('created_at', '>=', now()->subDay())->count(),
            'hourly' => self::hourly(),
            'open' => DB::table('error_groups')->whereNull('resolved_at')->count(),
            'mail_failures_7d' => DB::table('error_events')
                ->join('error_groups', 'error_groups.id', '=', 'error_events.error_group_id')
                ->where('error_groups.category', 'mail')
                ->where('error_events.created_at', '>=', now()->subDays(7))
                ->count(),
            'top' => DB::table('error_groups')
                ->whereNull('resolved_at')
                ->where('last_seen_at', '>=', now()->subDays(7))
                ->orderByDesc('last_seen_at')
                ->limit(5)
                ->get(['id', 'category', 'exception_class', 'message', 'context', 'occurrences', 'last_seen_at']),
        ];
    }

    /** 24 compteurs horaires, du plus ancien au plus récent (heure courante incluse). */
    private static function hourly(?int $groupId = null): array
    {
        $start = now()->startOfHour()->subHours(23);
        $times = DB::table('error_events')
            ->where('created_at', '>=', $start)
            ->when($groupId, fn ($q) => $q->where('error_group_id', $groupId))
            ->pluck('created_at');

        $buckets = array_fill(0, 24, 0);
        foreach ($times as $time) {
            $index = (int) $start->diffInHours(\Illuminate\Support\Carbon::parse($time)->startOfHour());
            if ($index >= 0 && $index < 24) {
                $buckets[$index]++;
            }
        }

        return $buckets;
    }
}
