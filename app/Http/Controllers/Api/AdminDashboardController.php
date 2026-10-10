<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\School;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tableau de bord plateforme (super-admin) : KPIs, santé des écoles,
 * points à traiter et état technique.
 */
class AdminDashboardController extends Controller
{
    private const INACTIVE_DAYS = 14;

    public function index(): JsonResponse
    {
        return response()->json([
            'kpis' => $this->kpis(),
            'schools' => $this->schoolsHealth(),
            'todo' => $this->todo(),
            'activity' => AuditLogController::recent(),
            'system' => $this->system(),
        ]);
    }

    /**
     * Recherche globale d'utilisateurs, toutes écoles confondues.
     * Filtre texte sur nom, prénom, email ou id ; filtres optionnels rôle et école.
     */
    public function users(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q' => 'nullable|string|max:100',
            'role' => 'nullable|string|max:50',
            'school_id' => 'nullable|integer',
            'population' => 'nullable|in:staff,families,all',
            'status' => 'nullable|in:never_logged,no_assignment,pending,no_access',
            'sort' => 'nullable|in:last_login,name,created',
            'dir' => 'nullable|in:asc,desc',
            'page' => 'nullable|integer|min:1',
        ]);

        $q = trim($validated['q'] ?? '');

        $query = User::query()->select(['id', 'first_name', 'last_name', 'email', 'access', 'last_login_at', 'became_staff_at', 'created_at']);

        if ($q !== '') {
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $q).'%';
            $query->where(function ($w) use ($q, $like) {
                $w->where('email', 'like', $like)
                    ->orWhere('first_name', 'like', $like)
                    ->orWhere('last_name', 'like', $like)
                    ->orWhereRaw("CONCAT(first_name, ' ', last_name) LIKE ?", [$like]);
                if (ctype_digit($q)) {
                    $w->orWhere('id', (int) $q);
                }
            });
        }

        if (! empty($validated['role'])) {
            $query->whereIn('id', DB::table('user_roles')
                ->join('roles', 'roles.id', '=', 'user_roles.role_id')
                ->where('roles.slug', $validated['role'])
                ->select('user_roles.user_id'));
        }

        if (! empty($validated['school_id'])) {
            $members = DB::query()->fromSub($this->schoolMembersQuery(), 'm')
                ->where('m.school_id', $validated['school_id'])
                ->select('m.user_id');
            $query->whereIn('id', $members);
        }

        // Équipes = staff actuel, ancien ou invité, et super-admins ; Familles = le reste
        match ($validated['population'] ?? 'staff') {
            'staff' => $query->where(fn ($w) => $this->whereStaffPopulation($w)),
            'families' => $query->whereNot(fn ($w) => $this->whereStaffPopulation($w)),
            default => null,
        };

        match ($validated['status'] ?? null) {
            // Seul le staff a un espace : familles et élèves ne sont pas censés se connecter
            'never_logged' => $query->whereNull('last_login_at')->whereIn('id', $this->staffUserIds()),
            'no_assignment' => $this->whereWithoutAssignment($query),
            'pending' => $query->whereIn('id', DB::table('user_roles')->whereNull('accepted_at')->where('roleable_type', 'school')->select('user_id')),
            'no_access' => $query->where('access', false),
            default => null,
        };

        $dir = $validated['dir'] ?? null;
        match ($validated['sort'] ?? 'last_login') {
            'name' => $query->orderBy('last_name', $dir ?? 'asc')->orderBy('first_name', $dir ?? 'asc'),
            'created' => $query->orderBy('created_at', $dir ?? 'desc'),
            // NULL en dernier quel que soit le sens
            default => $query->orderByRaw('last_login_at IS NULL')->orderBy('last_login_at', $dir ?? 'desc')->orderBy('last_name'),
        };

        $page = $query->paginate(25);
        $ids = $page->getCollection()->pluck('id');

        $memberships = $this->membershipsFor($ids);

        $superAdmins = config('toollab.super_admin_emails', []);

        $page->getCollection()->transform(fn (User $u) => $this->presentUser($u, $memberships[$u->id] ?? collect(), $superAdmins));

        return response()->json($page);
    }

    /** Les comptes élèves sont créés avec un email factice « prenom.nom.student.<uniqid>@school.com ». */
    private const TECHNICAL_EMAIL_SUFFIX = '@school.com';

    private function isTechnicalAccount(User $u): bool
    {
        return str_ends_with($u->email, self::TECHNICAL_EMAIL_SUFFIX) && str_contains($u->email, '.student.');
    }

    private function membershipsFor($userIds)
    {
        return DB::table('user_roles')
            ->join('roles', 'roles.id', '=', 'user_roles.role_id')
            ->leftJoin('families', function ($join) {
                $join->on('families.id', '=', 'user_roles.roleable_id')
                    ->where('user_roles.roleable_type', 'family');
            })
            ->leftJoin('classrooms', function ($join) {
                $join->on('classrooms.id', '=', 'user_roles.roleable_id')
                    ->where('user_roles.roleable_type', 'classroom');
            })
            ->leftJoin('schools', 'schools.id', '=', DB::raw("CASE user_roles.roleable_type
                WHEN 'school' THEN user_roles.roleable_id
                WHEN 'family' THEN families.school_id
                WHEN 'classroom' THEN classrooms.school_id END"))
            ->whereIn('user_roles.user_id', $userIds)
            ->get([
                'user_roles.user_id', 'roles.name as role', 'roles.slug',
                'user_roles.roleable_type as scope', 'user_roles.roleable_id as scope_id', 'user_roles.accepted_at',
                'schools.id as school_id', 'schools.name as school',
            ])
            ->groupBy('user_id');
    }

    /** Population « équipes » : cf. User::canLogIn(). */
    private function whereStaffPopulation($query)
    {
        return $query->whereIn('email', config('toollab.super_admin_emails', []) ?: [''])
            ->orWhereNotNull('became_staff_at')
            ->orWhereIn('id', DB::table('user_roles')->whereIn('roleable_type', ['school', School::class])->select('user_id'));
    }

    /** Ancien staff : peut se connecter mais n'a plus aucune école (ni invitation en attente). */
    private function whereWithoutAssignment($query)
    {
        return $query->whereNotNull('became_staff_at')
            ->whereNotIn('email', config('toollab.super_admin_emails', []) ?: [''])
            ->whereNotIn('id', DB::table('user_roles')->whereIn('roleable_type', ['school', School::class])->select('user_id'));
    }

    /** Membres du staff (rôle école accepté) : seuls à avoir un espace dans l'outil. Cf. User::isStaff(). */
    private function staffUserIds()
    {
        return DB::table('user_roles')
            ->where('roleable_type', 'school')
            ->whereNotNull('accepted_at')
            ->select('user_id');
    }

    private function presentUser(User $u, $memberships, array $superAdmins): array
    {
        $isSuperAdmin = in_array($u->email, $superAdmins, true);
        $technical = $this->isTechnicalAccount($u);
        $isStaff = $memberships->contains(fn ($m) => $m->scope === 'school' && $m->accepted_at !== null);

        return [
            'id' => $u->id,
            'first_name' => $u->first_name,
            'last_name' => $u->last_name,
            'email' => $technical ? null : $u->email,
            'technical_account' => $technical,
            'access' => (bool) $u->access,
            'is_super_admin' => $isSuperAdmin,
            'is_staff' => $isStaff,
            'is_former_staff' => ! $isStaff && ! $isSuperAdmin && $u->became_staff_at !== null
                && $memberships->doesntContain(fn ($m) => $m->scope === 'school'),
            'can_impersonate' => $isStaff && ! $isSuperAdmin && $u->access,
            'last_login_at' => $u->last_login_at,
            'created_at' => $u->created_at,
            'memberships' => $memberships->map(fn ($m) => [
                'role' => $m->role,
                'slug' => $m->slug,
                'scope' => $m->scope,
                'scope_id' => $m->scope_id,
                'school_id' => $m->school_id,
                'school' => $m->school,
                'pending' => $m->scope === 'school' && $m->accepted_at === null,
            ])->values(),
        ];
    }

    /** Fiche détaillée d'un utilisateur (panneau latéral de /admin/users). */
    public function showUser(User $user): JsonResponse
    {
        $memberships = $this->membershipsFor([$user->id])[$user->id] ?? collect();
        $base = $this->presentUser($user, $memberships, config('toollab.super_admin_emails', []));

        // Familles : tous les membres, avec leur rôle
        $familyIds = $memberships->where('scope', 'family')->pluck('scope_id')->unique();
        $families = $familyIds->map(function ($familyId) {
            $members = DB::table('user_roles')
                ->join('users', 'users.id', '=', 'user_roles.user_id')
                ->join('roles', 'roles.id', '=', 'user_roles.role_id')
                ->where('user_roles.roleable_type', 'family')
                ->where('user_roles.roleable_id', $familyId)
                ->orderBy('roles.slug')
                ->get(['users.id', 'users.first_name', 'users.last_name', 'roles.name as role', 'roles.slug']);

            return ['id' => $familyId, 'members' => $members];
        })->values();

        // Classes : inscription (élève) et enseignement (prof principal ou créneau)
        $enrolled = DB::table('student_classrooms')
            ->join('classrooms', 'classrooms.id', '=', 'student_classrooms.classroom_id')
            ->where('student_classrooms.student_id', $user->id)
            ->get(['classrooms.id', 'classrooms.name', 'student_classrooms.status']);

        $taught = DB::table('classrooms')
            ->where('main_teacher_id', $user->id)
            ->orWhereIn('id', DB::table('class_schedules')->where('teacher_id', $user->id)->select('classroom_id'))
            ->get(['id', 'name', DB::raw("CASE WHEN main_teacher_id = {$user->id} THEN 1 ELSE 0 END as is_main")]);

        $sessions = DB::table('personal_access_tokens')
            ->where('tokenable_type', User::class)
            ->where('tokenable_id', $user->id)
            ->orderByDesc('created_at')
            ->limit(10)
            ->get(['id', 'name', 'created_at', 'last_used_at', 'expires_at'])
            ->map(fn ($t) => [
                'created_at' => $t->created_at,
                'last_used_at' => $t->last_used_at,
                'impersonation' => $t->name === \App\Models\Impersonation::TOKEN_NAME,
            ]);


        return response()->json([
            ...$base,
            'disabled_at' => $user->disabled_at,
            'disabled_reason' => $user->disabled_reason,
            'families' => $families,
            'classrooms_enrolled' => $enrolled,
            'classrooms_taught' => $taught,
            'sessions' => $sessions,
        ]);
    }

    private function kpis(): array
    {
        $roles = DB::table('user_roles')
            ->join('roles', 'roles.id', '=', 'user_roles.role_id')
            // accepted_at ne concerne que les invitations staff (rôles école)
            ->where(fn ($q) => $q->whereNotNull('user_roles.accepted_at')->orWhere('user_roles.roleable_type', '!=', 'school'))
            ->groupBy('roles.slug')
            ->selectRaw('roles.slug, COUNT(DISTINCT user_roles.user_id) as total')
            ->pluck('total', 'slug');

        $activeSince = fn (int $days) => DB::table('personal_access_tokens')
            ->where('tokenable_type', \App\Models\User::class)
            ->where('name', '!=', \App\Models\Impersonation::TOKEN_NAME)
            ->where('last_used_at', '>=', now()->subDays($days))
            ->distinct()
            ->count('tokenable_id');

        $usersThisMonth = DB::table('users')->where('created_at', '>=', now()->startOfMonth())->count();
        $usersLastMonth = DB::table('users')
            ->whereBetween('created_at', [now()->subMonthNoOverflow()->startOfMonth(), now()->startOfMonth()])
            ->count();

        return [
            'schools_total' => School::count(),
            'schools_active' => School::where('access', true)->count(),
            'schools_suspended' => School::where('access', false)->count(),
            'users_total' => DB::table('users')->count(),
            'users_by_role' => [
                'director' => (int) ($roles['director'] ?? 0),
                'teacher' => (int) ($roles['teacher'] ?? 0),
                'student' => DB::table('student_classrooms')->where('status', 'active')->distinct()->count('student_id'),
                'responsible' => (int) ($roles['responsible'] ?? 0),
            ],
            'active_7d' => $activeSince(7),
            'active_30d' => $activeSince(30),
            'signups_this_month' => $usersThisMonth,
            'signups_last_month' => $usersLastMonth,
        ];
    }

    /** Utilisateurs rattachés à chaque école : staff (rôle école) + membres des familles de l'école. */
    private function schoolMembersQuery()
    {
        $staff = DB::table('user_roles')
            ->where('roleable_type', 'school')
            ->select('roleable_id as school_id', 'user_id');

        $families = DB::table('user_roles')
            ->join('families', 'families.id', '=', 'user_roles.roleable_id')
            ->where('user_roles.roleable_type', 'family')
            ->whereNull('families.deleted_at')
            ->select('families.school_id', 'user_roles.user_id');

        return $staff->union($families);
    }

    private function schoolsHealth(): array
    {
        $lastActivity = DB::query()
            ->fromSub($this->schoolMembersQuery(), 'm')
            ->join('personal_access_tokens as t', function ($join) {
                $join->on('t.tokenable_id', '=', 'm.user_id')
                    ->where('t.tokenable_type', \App\Models\User::class)
                    ->where('t.name', '!=', \App\Models\Impersonation::TOKEN_NAME);
            })
            ->groupBy('m.school_id')
            ->selectRaw('m.school_id, MAX(t.last_used_at) as last_activity')
            ->pluck('last_activity', 'school_id');

        $countByRole = fn (string $slug) => DB::table('user_roles')
            ->join('roles', 'roles.id', '=', 'user_roles.role_id')
            ->where('roles.slug', $slug)
            ->where('user_roles.roleable_type', 'school')
            ->whereNotNull('user_roles.accepted_at')
            ->groupBy('user_roles.roleable_id')
            ->selectRaw('user_roles.roleable_id as school_id, COUNT(DISTINCT user_roles.user_id) as total')
            ->pluck('total', 'school_id');

        $teachers = $countByRole('teacher');
        $directors = $countByRole('director');

        $students = DB::table('student_classrooms')
            ->join('classrooms', 'classrooms.id', '=', 'student_classrooms.classroom_id')
            ->where('student_classrooms.status', 'active')
            ->groupBy('classrooms.school_id')
            ->selectRaw('classrooms.school_id, COUNT(DISTINCT student_classrooms.student_id) as total')
            ->pluck('total', 'school_id');

        $classrooms = DB::table('classrooms')
            ->groupBy('school_id')
            ->selectRaw('school_id, COUNT(*) as total')
            ->pluck('total', 'school_id');

        return School::orderBy('name')->get(['id', 'name', 'city', 'access', 'created_at'])
            ->map(function (School $school) use ($lastActivity, $teachers, $directors, $students, $classrooms) {
                $last = $lastActivity[$school->id] ?? null;
                $nbClassrooms = (int) ($classrooms[$school->id] ?? 0);
                $nbTeachers = (int) ($teachers[$school->id] ?? 0);

                $alerts = [];
                if (! $last || Carbon::parse($last)->lt(now()->subDays(self::INACTIVE_DAYS))) {
                    $alerts[] = 'inactive';
                }
                if ($nbClassrooms === 0 || $nbTeachers === 0) {
                    $alerts[] = 'onboarding';
                }
                if ((int) ($directors[$school->id] ?? 0) === 0) {
                    $alerts[] = 'no_director';
                }

                return [
                    'id' => $school->id,
                    'name' => $school->name,
                    'city' => $school->city,
                    'access' => (bool) $school->access,
                    'created_at' => $school->created_at,
                    'students' => (int) ($students[$school->id] ?? 0),
                    'teachers' => $nbTeachers,
                    'classrooms' => $nbClassrooms,
                    'last_activity' => $last,
                    'alerts' => $alerts,
                ];
            })
            ->values()
            ->all();
    }

    private function todo(): array
    {
        $pendingInvitations = DB::table('user_roles')
            ->join('users', 'users.id', '=', 'user_roles.user_id')
            ->join('roles', 'roles.id', '=', 'user_roles.role_id')
            ->leftJoin('schools', function ($join) {
                $join->on('schools.id', '=', 'user_roles.roleable_id')
                    ->where('user_roles.roleable_type', 'school');
            })
            ->whereNull('user_roles.accepted_at')
            ->where('user_roles.created_at', '<', now()->subDays(3))
            ->orderBy('user_roles.created_at')
            ->limit(20)
            ->get([
                'users.id as user_id', 'users.email', 'roles.name as role',
                'schools.name as school', 'user_roles.created_at',
            ]);

        $withoutAssignment = $this->whereWithoutAssignment(User::query());
        $orphans = (clone $withoutAssignment)
            ->orderByDesc('updated_at')
            ->limit(20)
            ->get(['id', 'first_name', 'last_name', 'email', 'created_at']);

        return [
            'pending_invitations_count' => DB::table('user_roles')->whereNull('accepted_at')->count(),
            'pending_invitations' => $pendingInvitations,
            'expired_tokens_count' => DB::table('invitation_tokens')->where('expires_at', '<', now())->count(),
            'unassigned_users' => $orphans,
            'unassigned_users_count' => $withoutAssignment->count(),
        ];
    }

    private function system(): array
    {
        try {
            $start = microtime(true);
            DB::select('SELECT 1');
            $db = ['ok' => true, 'latency_ms' => round((microtime(true) - $start) * 1000, 1)];
        } catch (\Throwable $e) {
            $db = ['ok' => false, 'error' => $e->getMessage()];
        }

        $ran = DB::table('migrations')->pluck('migration')->all();
        $files = collect(glob(database_path('migrations/*.php')))
            ->map(fn ($f) => basename($f, '.php'));
        $pending = $files->diff($ran)->values();

        return [
            'environment' => app()->environment(),
            'debug' => (bool) config('app.debug'),
            'version' => config('toollab.version'),
            'commit' => config('toollab.commit'),
            'php' => PHP_VERSION,
            'laravel' => app()->version(),
            'database' => $db,
            'migrations_pending' => $pending,
            'queue' => [
                'connection' => config('queue.default'),
                'jobs_waiting' => Schema::hasTable('jobs') ? DB::table('jobs')->count() : null,
                'jobs_failed' => Schema::hasTable('failed_jobs') ? DB::table('failed_jobs')->count() : null,
                'last_failed' => Schema::hasTable('failed_jobs')
                    ? DB::table('failed_jobs')->orderByDesc('failed_at')->limit(5)
                        ->get(['id', 'queue', 'failed_at', 'exception'])
                        ->map(fn ($j) => [...(array) $j, 'exception' => mb_substr($j->exception, 0, 200)])
                    : [],
            ],
            'mail' => config('mail.default'),
            'server_time' => now()->toIso8601String(),
        ];
    }
}
