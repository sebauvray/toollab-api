<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Journal des actions sensibles (super-admin). */
class AuditLogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'action' => 'nullable|string|max:64',
            'school_id' => 'nullable|integer',
            'user_id' => 'nullable|integer',
            'q' => 'nullable|string|max:100',
            'page' => 'nullable|integer|min:1',
        ]);

        $query = AuditLog::query()->latest('id');

        if (! empty($validated['action'])) {
            // « staff,invitation » filtre les familles staff.* et invitation.* ; « staff.role_added » une action précise
            $query->where(function ($w) use ($validated) {
                foreach (explode(',', $validated['action']) as $action) {
                    str_contains($action, '.')
                        ? $w->orWhere('action', $action)
                        : $w->orWhere('action', 'like', $action.'.%');
                }
            });
        }
        if (! empty($validated['school_id'])) {
            $query->where('school_id', $validated['school_id']);
        }
        if (! empty($validated['user_id'])) {
            // Tout ce que la personne a fait ou subi
            $query->where(fn ($w) => $w->where('actor_id', $validated['user_id'])
                ->orWhere(fn ($s) => $s->where('subject_type', 'user')->where('subject_id', $validated['user_id'])));
        }
        if (! empty($validated['q'])) {
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $validated['q']).'%';
            $query->where(fn ($w) => $w->where('actor_label', 'like', $like)->orWhere('subject_label', 'like', $like));
        }

        return response()->json($this->present($query->paginate(30)));
    }

    public static function recent(int $limit = 8): array
    {
        return (new self)->present(AuditLog::latest('id')->limit($limit)->get())->all();
    }

    private function present($logs)
    {
        $collection = $logs instanceof \Illuminate\Pagination\AbstractPaginator ? $logs->getCollection() : $logs;
        $schools = DB::table('schools')->whereIn('id', $collection->pluck('school_id')->filter()->unique())->pluck('name', 'id');

        $mapped = $collection->map(fn (AuditLog $log) => [
            'id' => $log->id,
            'action' => $log->action,
            'action_label' => Audit::ACTIONS[$log->action] ?? $log->action,
            'actor_id' => $log->actor_id,
            'actor_label' => $log->actor_label,
            'impersonated' => $log->impersonation_id !== null,
            'school_id' => $log->school_id,
            'school' => $schools[$log->school_id] ?? null,
            'subject_type' => $log->subject_type,
            'subject_id' => $log->subject_id,
            'subject_label' => $log->subject_label,
            'meta' => $log->meta,
            'created_at' => $log->created_at,
        ]);

        if ($logs instanceof \Illuminate\Pagination\AbstractPaginator) {
            $logs->setCollection($mapped);

            return $logs;
        }

        return $mapped;
    }
}
