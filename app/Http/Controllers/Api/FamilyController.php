<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Family;
use App\Models\Role;
use App\Models\User;
use App\Models\UserInfo;
use App\Models\UserRole;
use App\Models\StudentClassroom;
use App\Traits\PaginationTrait;
use App\Services\PaiementService;
use App\Services\ExportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Auth;

class FamilyController extends Controller
{
    use PaginationTrait;
    const KEY_PHONE = 'phone';
    const KEY_ADDRESS = 'address';
    const KEY_ZIPCODE = 'zipcode';
    const KEY_CITY = 'city';
    const KEY_BIRTHDATE = 'birthdate';
    const KEY_GENDER = 'gender';

    protected $paiementService;

    public function __construct(PaiementService $paiementService)
    {
        $this->paiementService = $paiementService;
    }

    /**
     * Le caller (auth user) a-t-il le droit d'accéder à $family ?
     * - super-admin : oui
     * - director / admin / registar de l'école : oui
     * - rattaché à la famille (responsible ou student) : oui
     * - sinon : non (renvoyer 403)
     */
    public static function callerCanAccessFamily(Family $family): bool
    {
        $caller = auth()->user();
        if (!$caller) return false;
        if ($caller->is_super_admin) return true;

        $schoolId = currentSchoolId();
        if ($schoolId !== null && $family->school_id !== $schoolId) {
            return false;
        }

        $isStaff = UserRole::where('user_id', $caller->id)
            ->where('roleable_type', 'school')
            ->where('roleable_id', $family->school_id)
            ->whereHas('role', fn ($q) => $q->whereIn('slug', ['director', 'admin', 'registar']))
            ->exists();
        if ($isStaff) return true;

        return UserRole::where('user_id', $caller->id)
            ->where('roleable_type', 'family')
            ->where('roleable_id', $family->id)
            ->exists();
    }

    private function denyFamilyAccess(Family $family): \Illuminate\Http\JsonResponse
    {
        \Illuminate\Support\Facades\Log::warning('FamilyController: cross-family access denied', [
            'caller_id' => auth()->id(),
            'family_id' => $family->id,
        ]);
        return response()->json(['status' => 'error', 'message' => 'Accès refusé'], 403);
    }

    /**
     * Vérifie que $user a un rôle $roleSlug dans le contexte $family.
     * Retourne null si OK, sinon une JsonResponse 404 générique.
     */
    private function ensureMemberOfFamily(User $user, Family $family, string $roleSlug): ?\Illuminate\Http\JsonResponse
    {
        $belongs = UserRole::where('user_id', $user->id)
            ->where('roleable_type', 'family')
            ->where('roleable_id', $family->id)
            ->whereHas('role', fn($q) => $q->where('slug', $roleSlug))
            ->exists();

        if (!$belongs) {
            \Illuminate\Support\Facades\Log::warning('FamilyController: target user not in family', [
                'caller_id' => auth()->id(),
                'family_id' => $family->id,
                'target_user_id' => $user->id,
                'expected_role' => $roleSlug,
            ]);
            return response()->json(['status' => 'error', 'message' => 'Ressource introuvable'], 404);
        }
        return null;
    }

    public function index(Request $request)
    {
        $query = Family::query();

        $caller = auth()->user();
        $schoolId = currentSchoolId();
        $isStaff = $caller && ($caller->is_super_admin || UserRole::where('user_id', $caller->id)
            ->where('roleable_type', 'school')
            ->where('roleable_id', $schoolId)
            ->whereHas('role', fn ($q) => $q->whereIn('slug', ['director', 'admin', 'registar']))
            ->exists());

        if (!$isStaff) {
            $myFamilyIds = UserRole::where('user_id', $caller->id)
                ->where('roleable_type', 'family')
                ->pluck('roleable_id');
            $query->whereIn('id', $myFamilyIds);
        }

        $search = trim((string) $request->input('search', ''));
        if ($search !== '') {
            $query->whereHas('userRoles', function ($q) use ($search) {
                $q->whereHas('role', fn ($roleQuery) => $roleQuery->where('slug', 'responsible'))
                    ->whereHas('user', function ($userQuery) use ($search) {
                        $userQuery->where('first_name', 'like', '%' . $search . '%')
                            ->orWhere('last_name', 'like', '%' . $search . '%')
                            ->orWhereRaw("CONCAT(first_name, ' ', last_name) LIKE ?", ['%' . $search . '%'])
                            ->orWhereRaw("CONCAT(last_name, ' ', first_name) LIKE ?", ['%' . $search . '%']);
                    });
            });
        }

        $query->with(['userRoles' => function ($q) {
            $q->whereHas('role', function ($query) {
                $query->where('slug', 'responsible');
            })->with(['user', 'user.infos']);
        }]);

        $query->withCount(['userRoles as students_count' => function ($q) {
            $q->whereHas('role', function ($query) {
                $query->where('slug', 'student');
            });
        }]);

        $query->withCount(['studentClassrooms as active_inscriptions_count' => function ($q) {
            $q->where('status', 'active');
        }]);

        $sortBy = (string) $request->input('sort_by', 'created_at');
        $sortDirection = strtolower((string) $request->input('sort_direction', 'desc')) === 'asc' ? 'asc' : 'desc';
        $sortableColumns = ['created_at', 'nom', 'nombreEleves', 'status'];
        if (!in_array($sortBy, $sortableColumns, true)) {
            $sortBy = 'created_at';
        }
        $paymentStatus = (string) $request->input('payment_status', 'all');
        $allowedPaymentStatuses = ['all', 'no_enrollment', 'incomplete', 'pending', 'paid', 'exempted'];
        if (!in_array($paymentStatus, $allowedPaymentStatuses, true)) {
            $paymentStatus = 'all';
        }

        if ($sortBy === 'nom') {
            $responsibleNameSubquery = UserRole::query()
                ->selectRaw("LOWER(CONCAT(users.last_name, ' ', users.first_name))")
                ->join('roles', 'user_roles.role_id', '=', 'roles.id')
                ->join('users', 'user_roles.user_id', '=', 'users.id')
                ->whereColumn('user_roles.roleable_id', 'families.id')
                ->where('user_roles.roleable_type', 'family')
                ->where('roles.slug', 'responsible')
                ->orderBy('users.last_name')
                ->orderBy('users.first_name')
                ->limit(1);

            $query->orderBy($responsibleNameSubquery, $sortDirection);
        } elseif ($sortBy === 'nombreEleves') {
            $query->orderBy('students_count', $sortDirection);
        } elseif ($sortBy !== 'status') {
            $query->orderBy('created_at', $sortDirection);
        }

        $formatFamily = function ($family) {
            $responsables = $family->userRoles;
            $premier = $responsables->first(fn ($r) => $r->user);

            $noms = $responsables
                ->map(fn ($r) => $r->user ? trim(mb_strtoupper($r->user->last_name) . ' ' . $r->user->first_name) : null)
                ->filter()
                ->values();

            $userInfos = $premier && $premier->user ? collect($premier->user->infos)->pluck('value', 'key')->toArray() : [];

            return [
                'id' => $family->id,
                'nom' => $noms->isNotEmpty() ? $noms->implode(', ') : 'Sans responsable',
                'nombreEleves' => $family->students_count,
                'status' => $this->calculatePaymentStatus($family),
                'dateInscription' => $family->created_at->locale('fr_FR')->format('d M Y, H:i'),
                'contact' => [
                    'phone' => $userInfos[self::KEY_PHONE] ?? null,
                    'email' => $premier && $premier->user ? $premier->user->email : null,
                    'address' => $userInfos[self::KEY_ADDRESS] ?? null,
                    'zipcode' => $userInfos[self::KEY_ZIPCODE] ?? null,
                    'city' => $userInfos[self::KEY_CITY] ?? null
                ]
            ];
        };

        if ($sortBy === 'status' || $paymentStatus !== 'all') {
            $items = $query->get()->map($formatFamily);

            if ($paymentStatus !== 'all') {
                $items = $items
                    ->filter(fn ($family) => $family['status'] === $paymentStatus)
                    ->values();
            }

            $statusOrder = [
                'no_enrollment' => 0,
                'exempted' => 1,
                'incomplete' => 2,
                'pending' => 3,
                'paid' => 4,
            ];

            if ($sortBy === 'status') {
                $items = $items->sortBy(
                    fn ($family) => $statusOrder[$family['status']] ?? 99,
                    SORT_REGULAR,
                    $sortDirection === 'desc'
                )->values();
            }

            $page = (int) $request->input('page', 1);
            $perPage = min((int) $request->input('per_page', 10), 100);
            $total = $items->count();
            $formattedItems = $items->slice(($page - 1) * $perPage, $perPage)->values();
            $pagination = [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'total_pages' => (int) ceil($total / $perPage),
            ];
        } else {
            $paginatedData = $this->paginateQuery($query, $request);
            $formattedItems = collect($paginatedData['items'])->map($formatFamily);
            $pagination = $paginatedData['pagination'];
        }

        return response()->json([
            'status' => 'success',
            'data' => [
                'items' => $formattedItems,
                'pagination' => $pagination
            ]
        ]);
    }

    public function exportStudents(Request $request)
    {
        $schoolId = currentSchoolId();

        $familyIds = Family::query()->where('school_id', $schoolId)->pluck('id');

        $rolesByFamily = UserRole::where('roleable_type', 'family')
            ->whereIn('roleable_id', $familyIds)
            ->whereHas('role', fn ($q) => $q->whereIn('slug', ['responsible', 'student']))
            ->with(['role:id,slug', 'user.infos'])
            ->get()
            ->groupBy('roleable_id');

        $studentIds = UserRole::where('roleable_type', 'family')
            ->whereIn('roleable_id', $familyIds)
            ->whereHas('role', fn ($q) => $q->where('slug', 'student'))
            ->pluck('user_id')
            ->unique();

        $classesByStudent = StudentClassroom::query()
            ->where('status', 'active')
            ->whereIn('student_id', $studentIds)
            ->whereHas('classroom')
            ->with('classroom:id,name')
            ->get()
            ->groupBy('student_id')
            ->map(fn ($group) => $group->map(fn ($sc) => $sc->classroom?->name)->filter()->unique()->values()->implode(', '));

        $rows = [];
        foreach ($familyIds as $familyId) {
            $roles = $rolesByFamily->get($familyId, collect());

            $responsibles = $roles->filter(fn ($ur) => $ur->role->slug === 'responsible' && $ur->user)->values();
            $students = $roles->filter(fn ($ur) => $ur->role->slug === 'student' && $ur->user)->values();

            $respNames = $responsibles->map(fn ($ur) => trim($ur->user->first_name . ' ' . $ur->user->last_name))->filter()->implode(', ');
            $primary = $responsibles->first();
            $primaryInfos = $primary ? collect($primary->user->infos)->pluck('value', 'key')->toArray() : [];

            foreach ($students as $sur) {
                $studentInfos = collect($sur->user->infos)->pluck('value', 'key')->toArray();
                $rows[] = [
                    'last_name' => $sur->user->last_name,
                    'first_name' => $sur->user->first_name,
                    'birthdate' => $studentInfos[self::KEY_BIRTHDATE] ?? null,
                    'classes' => $classesByStudent->get($sur->user->id, ''),
                    'responsibles' => $respNames ?: null,
                    'email' => $primary?->user?->email,
                    'phone' => $primaryInfos[self::KEY_PHONE] ?? null,
                    'address' => $primaryInfos[self::KEY_ADDRESS] ?? null,
                    'zipcode' => $primaryInfos[self::KEY_ZIPCODE] ?? null,
                    'city' => $primaryInfos[self::KEY_CITY] ?? null,
                ];
            }
        }

        usort($rows, fn ($a, $b) => mb_strtolower(($a['last_name'] ?? '') . ' ' . ($a['first_name'] ?? '')) <=> mb_strtolower(($b['last_name'] ?? '') . ' ' . ($b['first_name'] ?? '')));

        $headers = ['Nom élève', 'Prénom élève', 'Date de naissance', 'Classes', 'Responsable(s)', 'Email responsable', 'Téléphone', 'Adresse', 'Code postal', 'Ville'];

        $data = array_map(fn ($r) => array_values($r), $rows);

        return ExportService::xlsx('eleves', $headers, $data);
    }

    private function calculatePaymentStatus(Family $family): string
    {
        if ($family->active_inscriptions_count == 0) {
            return 'no_enrollment';
        }

        try {
            $details = $this->paiementService->getDetailsPaiement($family);

            $montantTotal = $details['montant_total'] ?? 0;
            $montantPaye = $details['montant_paye'] ?? 0;
            $resteAPayer = $details['reste_a_payer'] ?? $montantTotal;

            if ($montantTotal == 0) {
                return 'exempted';
            }

            if ($resteAPayer <= 0) {
                return 'paid';
            }

            if ($montantPaye == 0) {
                return 'incomplete';
            }

            return 'pending';

        } catch (\Exception $e) {
            return '';
        }
    }

    public function store(Request $request)
    {
        $request->validate([
            'lastname' => 'required|string|max:255',
            'firstname' => 'required|string|max:255',
            'phone' => 'required|string',
            'address' => 'required|string',
            'email' => 'required|email',
            'zipcode' => 'required|string',
            'city' => 'required|string',
            'is_student' => 'boolean',
            'birthdate' => 'required_if:is_student,true|nullable|date',
            'gender' => 'required_if:is_student,true|nullable|in:M,F',
        ], [
            'lastname.required' => 'Le nom est requis.',
            'lastname.string' => 'Le nom doit être une chaîne de caractères.',
            'lastname.max' => 'Le nom ne peut pas dépasser 255 caractères.',

            'firstname.required' => 'Le prénom est requis.',
            'firstname.string' => 'Le prénom doit être une chaîne de caractères.',
            'firstname.max' => 'Le prénom ne peut pas dépasser 255 caractères.',

            'email.required' => 'L\'adresse email est requise.',
            'email.email' => 'L\'adresse email doit être une adresse valide.',
            'phone.required' => 'Le numéro de téléphone est requis.',
            'phone.string' => 'Le numéro de téléphone doit être une chaîne de caractères.',

            'address.required' => 'L\'adresse est requise.',
            'address.string' => 'L\'adresse doit être une chaîne de caractères.',

            'zipcode.required' => 'Le code postal est requis.',
            'zipcode.string' => 'Le code postal doit être une chaîne de caractères.',

            'city.required' => 'La ville est requise.',
            'city.string' => 'La ville doit être une chaîne de caractères.',

            'is_student.boolean' => 'Le champ "est étudiant" doit être vrai ou faux.',

            'birthdate.required_if' => 'La date de naissance est obligatoire pour les étudiants.',
            'birthdate.date' => 'La date de naissance doit être une date valide.',

            'gender.required_if' => 'Le genre est obligatoire pour les étudiants.',
            'gender.in' => 'Le genre doit être "M" (masculin) ou "F" (féminin).',
        ]);

        DB::beginTransaction();

        try {
            $schoolId = currentSchoolId();
            if ($schoolId === null) {
                throw new \Exception('Requête invalide');
            }

            $user = $this->findOrCreateResponsibleUser($request);

            $responsibleRole = Role::where('slug', 'responsible')->first();

            if (!$responsibleRole) {
                throw new \Exception('Role responsible not found');
            }

            $this->updateOrCreateUserInfo($user, self::KEY_PHONE, $request->phone);
            $this->updateOrCreateUserInfo($user, self::KEY_ADDRESS, $request->address);
            $this->updateOrCreateUserInfo($user, self::KEY_ZIPCODE, $request->zipcode);
            $this->updateOrCreateUserInfo($user, self::KEY_CITY, $request->city);

            $family = Family::create();

            $family->userRoles()->firstOrCreate([
                'user_id' => $user->id,
                'role_id' => $responsibleRole->id,
            ]);

            if ($request->is_student) {
                $studentRole = Role::where('slug', 'student')->first();

                if ($studentRole) {
                    $hasStudentRole = $user->roles()
                        ->where('role_id', $studentRole->id)
                        ->where('roleable_id', $family->id)
                        ->where('roleable_type', 'family')
                        ->exists();

                    if (!$hasStudentRole) {
                        $family->userRoles()->create([
                            'user_id' => $user->id,
                            'role_id' => $studentRole->id,
                        ]);
                    }

                    $this->updateOrCreateUserInfo($user, self::KEY_BIRTHDATE, $request->birthdate);
                    $this->updateOrCreateUserInfo($user, self::KEY_GENDER, $request->gender);
                }
            }

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Famille créée avec succès',
                'data' => [
                    'family_id' => $family->id,
                    'user' => $user
                ]
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            \Illuminate\Support\Facades\Log::error('Family.store failed', ['exception' => $e]);
            return response()->json([
                'status' => 'error',
                'message' => 'Une erreur est survenue lors de la création de la famille',
            ], 500);
        }
    }

    public function show(Family $family)
    {
        if (!self::callerCanAccessFamily($family)) return $this->denyFamilyAccess($family);

        $responsibles = $family->userRoles()
            ->with(['user', 'user.infos', 'role'])
            ->whereHas('role', function ($query) {
                $query->where('slug', 'responsible');
            })
            ->get()
            ->map(function ($userRole) {
                $user = $userRole->user;

                $userInfos = collect($user->infos)->pluck('value', 'key')->toArray();

                return [
                    'id' => $user->id,
                    'first_name' => $user->first_name,
                    'last_name' => $user->last_name,
                    'email' => $user->email,
                    'phone' => $userInfos[self::KEY_PHONE] ?? null,
                    'address' => $userInfos[self::KEY_ADDRESS] ?? null,
                    'zipcode' => $userInfos[self::KEY_ZIPCODE] ?? null,
                    'city' => $userInfos[self::KEY_CITY] ?? null,
                    'role' => $userRole->role->name,
                    'is_student'=>$this->isUserStudent($user->id, $userRole->roleable_id),
                    'birthdate' => $userInfos[self::KEY_BIRTHDATE] ?? null,
                    'gender' => $userInfos['gender'] ?? null,
                ];
            });

        $students = $family->userRoles()
            ->with(['user', 'user.infos', 'role', 'user.studentClassrooms.classroom'])
            ->whereHas('role', function ($query) {
                $query->where('slug', 'student');
            })
            ->get()
            ->map(function ($userRole) use ($family) {
                $user = $userRole->user;

                $userInfos = collect($user->infos)->pluck('value', 'key')->toArray();

                $activeClassrooms = $user->studentClassrooms()
                    ->where('family_id', $family->id)
                    ->where('status', 'active')
                    ->with('classroom')
                    ->get()
                    ->map(function ($studentClassroom) {
                        return $studentClassroom->classroom;
                    });

                return [
                    'id' => $user->id,
                    'first_name' => $user->first_name,
                    'last_name' => $user->last_name,
                    'birthdate' => $userInfos[self::KEY_BIRTHDATE] ?? null,
                    'gender' => $userInfos['gender'] ?? null,
                    'is_responsible' => $this->isUserResponsible($user->id, $userRole->roleable_id),
                    'role' => $userRole->role->name,
                    'classrooms' => $activeClassrooms,
                    'created_at' => $userRole->created_at,
                ];
            });

        $comments = $family->comments()
            ->with('user')
            ->orderBy('created_at')
            ->get()
            ->map(function ($comment) {
                return [
                    'id' => $comment->id,
                    'content' => $comment->content,
                    'author' => $comment->user ? $comment->user->first_name . ' ' . $comment->user->last_name : 'Utilisateur inconnu',
                    'date' => $comment->created_at->format('d/m/Y'),
                    'created_at' => $comment->created_at
                ];
            });

        return response()->json([
            'status' => 'success',
            'data' => [
                'family' => [
                    'id' => $family->id,
                    'responsibles' => $responsibles,
                    'students' => $students,
                    'comments' => $comments
                ]
            ]
        ]);
    }

    public function addComment(Request $request, Family $family)
    {
        if (!self::callerCanAccessFamily($family)) return $this->denyFamilyAccess($family);

        $request->validate([
            'content' => 'required|string',
            'author_name' => 'nullable|string'
        ]);

        $commentData = [
            'content' => $request->input('content')
        ];

        if (auth()->check()) {
            $commentData['user_id'] = auth()->id();
        }

        $comment = $family->comments()->create($commentData);

        $comment->load('user');

        $authorName = 'Utilisateur';
        if ($comment->user) {
            $authorName = $comment->user->first_name . ' ' . $comment->user->last_name;
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Commentaire ajouté avec succès',
            'comment' => [
                'id' => $comment->id,
                'content' => $comment->content,
                'author' => $authorName,
                'date' => $comment->created_at->format('d/m/Y'),
                'created_at' => $comment->created_at
            ]
        ]);
    }

    public function addStudents(Request $request, Family $family)
    {
        if (!self::callerCanAccessFamily($family)) return $this->denyFamilyAccess($family);

        $request->validate([
            'students' => 'required|array',
            'students.*.lastname' => 'required|string|max:255',
            'students.*.firstname' => 'required|string|max:255',
            'students.*.birthdate' => 'required|date',
            'students.*.gender' => 'required|in:M,F'
        ], [
            'students.required' => 'Vous devez ajouter au moins un élève.',
            'students.array' => 'La liste des élèves doit être un tableau.',

            'students.*.firstname.required' => 'Le prénom de chaque élève est requis.',
            'students.*.firstname.string' => 'Le prénom de chaque élève doit être une chaîne de caractères.',
            'students.*.firstname.max' => 'Le prénom de chaque élève ne peut pas dépasser 255 caractères.',

            'students.*.lastname.required' => 'Le nom de chaque élève est requis.',
            'students.*.lastname.string' => 'Le nom de chaque élève doit être une chaîne de caractères.',
            'students.*.lastname.max' => 'Le nom de chaque élève ne peut pas dépasser 255 caractères.',

            'students.*.birthdate.required' => 'La date de naissance de chaque élève est requise.',
            'students.*.birthdate.date' => 'La date de naissance de chaque élève doit être une date valide.',

            'students.*.gender.required' => 'Le genre de chaque élève est requis.',
            'students.*.gender.in' => 'Le genre de chaque élève doit être "M" (masculin) ou "F" (féminin).',
        ]);

        DB::beginTransaction();

        try {
            $addedStudents = [];
            $studentRole = Role::where('slug', 'student')->firstOrFail();

            foreach ($request->students as $studentData) {
                $email = strtolower($studentData['firstname'] . '.' . $studentData['lastname'] . '.student.' . uniqid() . '@school.com');

                $student = User::create([
                    'first_name' => $studentData['firstname'],
                    'last_name' => $studentData['lastname'],
                    'email' => $email,
                    'password' => bcrypt(str()->random(12)),
                    'access' => true
                ]);

                UserInfo::create([
                    'user_id' => $student->id,
                    'key' => 'birthdate',
                    'value' => $studentData['birthdate']
                ]);

                UserInfo::create([
                    'user_id' => $student->id,
                    'key' => 'gender',
                    'value' => $studentData['gender']
                ]);

                UserRole::create([
                    'user_id' => $student->id,
                    'role_id' => $studentRole->id,
                    'roleable_type' => 'family',
                    'roleable_id' => $family->id
                ]);

                $addedStudents[] = $student;
            }

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => count($addedStudents) . ' élève(s) ajouté(s) avec succès',
                'data' => $addedStudents
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'status' => 'error',
                'message' => 'Une erreur est survenue lors de l\'ajout des élèves',
                'error' => 'Une erreur est survenue'
            ], 500);
        }
    }

    public function updateStudent(Request $request, Family $family, User $student)
    {
        if (!self::callerCanAccessFamily($family)) return $this->denyFamilyAccess($family);
        if ($deny = $this->ensureMemberOfFamily($student, $family, 'student')) return $deny;

        $request->validate([
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'birthdate' => 'nullable|date',
            'gender' => 'required|in:M,F'
        ]);

        DB::beginTransaction();

        try {
            $student->first_name = $request->first_name;
            $student->last_name = $request->last_name;
            $student->save();

            $this->updateOrCreateUserInfo($student, self::KEY_BIRTHDATE, $request->birthdate);
            $this->updateOrCreateUserInfo($student, self::KEY_GENDER, $request->gender);

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Élève mis à jour avec succès',
                'data' => $student->refresh()
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'status' => 'error',
                'message' => 'Une erreur est survenue lors de la mise à jour de l\'élève',
                'error' => 'Une erreur est survenue'
            ], 500);
        }
    }

    public function deleteStudent(Family $family, User $student)
    {
        if (!self::callerCanAccessFamily($family)) return $this->denyFamilyAccess($family);
        if ($deny = $this->ensureMemberOfFamily($student, $family, 'student')) return $deny;

        DB::beginTransaction();

        try {
            DB::table('user_infos')->where('user_id', $student->id)->delete();
            DB::table('user_roles')->where('user_id', $student->id)->delete();
            $student->delete();

            DB::commit();

            return response()->json(['message' => 'Élève et données associées supprimés avec succès.']);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'message' => 'Une erreur est survenue lors de la suppression.',
                'error' => 'Une erreur est survenue'
            ], 500);
        }
    }

    public function addResponsible(Request $request, Family $family)
    {
        if (!self::callerCanAccessFamily($family)) return $this->denyFamilyAccess($family);

        $request->validate([
            'user_id' => 'required|exists:users,id'
        ]);

        DB::beginTransaction();

        try {
            $user = User::findOrFail($request->user_id);

            $userIsInSchool = UserRole::where('user_id', $user->id)
                ->where(function ($q) use ($family) {
                    $familyIds = Family::query()->withoutGlobalScopes()
                        ->where('school_id', $family->school_id)->pluck('id');
                    $classroomIds = \App\Models\Classroom::query()->withoutGlobalScopes()
                        ->where('school_id', $family->school_id)->pluck('id');
                    $q->where(function ($q2) use ($family) {
                        $q2->where('roleable_type', 'school')->where('roleable_id', $family->school_id);
                    })->orWhere(function ($q2) use ($familyIds) {
                        $q2->where('roleable_type', 'family')->whereIn('roleable_id', $familyIds);
                    })->orWhere(function ($q2) use ($classroomIds) {
                        $q2->where('roleable_type', 'classroom')->whereIn('roleable_id', $classroomIds);
                    });
                })->exists();

            if (!$userIsInSchool) {
                \Illuminate\Support\Facades\Log::warning('FamilyController.addResponsible: target user not in school', [
                    'caller_id' => auth()->id(),
                    'family_id' => $family->id,
                    'target_user_id' => $user->id,
                    'school_id' => $family->school_id,
                ]);
                return response()->json(['status' => 'error', 'message' => 'Ressource introuvable'], 404);
            }

            $responsibleRole = Role::where('slug', 'responsible')->firstOrFail();

            $existingRole = UserRole::where('user_id', $user->id)
                ->where('roleable_type', 'family')
                ->where('roleable_id', $family->id)
                ->first();

            if ($existingRole) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Cette personne fait déjà partie de la famille'
                ], 400);
            }

            UserRole::create([
                'user_id' => $user->id,
                'role_id' => $responsibleRole->id,
                'roleable_type' => 'family',
                'roleable_id' => $family->id
            ]);

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Responsable ajouté avec succès',
                'data' => $family->load(['userRoles.user', 'userRoles.role'])
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'status' => 'error',
                'message' => 'Une erreur est survenue',
                'error' => 'Une erreur est survenue'
            ], 500);
        }
    }

    public function addResponsibleToFamily(Family $family, Request $request)
    {
        if (!self::callerCanAccessFamily($family)) return $this->denyFamilyAccess($family);

        $request->validate([
            'lastname' => 'required|string|max:255',
            'firstname' => 'required|string|max:255',
            'phone' => 'required|string',
            'address' => 'required|string',
            'email' => 'required|email',
            'zipcode' => 'required|string',
            'city' => 'required|string',
            'is_student' => 'boolean',
            'birthdate' => 'required_if:is_student,true|nullable|date',
            'gender' => 'required_if:is_student,true|nullable|in:M,F',
        ], [
            'lastname.required' => 'Le nom est requis.',
            'lastname.string' => 'Le nom doit être une chaîne de caractères.',
            'lastname.max' => 'Le nom ne peut pas dépasser 255 caractères.',

            'firstname.required' => 'Le prénom est requis.',
            'firstname.string' => 'Le prénom doit être une chaîne de caractères.',
            'firstname.max' => 'Le prénom ne peut pas dépasser 255 caractères.',

            'email.required' => 'L\'adresse email est requise.',
            'email.email' => 'L\'adresse email doit être une adresse valide.',
            'phone.required' => 'Le numéro de téléphone est requis.',
            'phone.string' => 'Le numéro de téléphone doit être une chaîne de caractères.',

            'address.required' => 'L\'adresse est requise.',
            'address.string' => 'L\'adresse doit être une chaîne de caractères.',

            'zipcode.required' => 'Le code postal est requis.',
            'zipcode.string' => 'Le code postal doit être une chaîne de caractères.',

            'city.required' => 'La ville est requise.',
            'city.string' => 'La ville doit être une chaîne de caractères.',

            'is_student.boolean' => 'Le champ "est étudiant" doit être vrai ou faux.',

            'birthdate.required_if' => 'La date de naissance est obligatoire pour les étudiants.',
            'birthdate.date' => 'La date de naissance doit être une date valide.',

            'gender.required_if' => 'Le genre est obligatoire pour les étudiants.',
            'gender.in' => 'Le genre doit être "M" (masculin) ou "F" (féminin).',
        ]);

        DB::beginTransaction();

        try {
            $user = $this->findOrCreateResponsibleUser($request);

            $responsibleRole = Role::where('slug', 'responsible')->first();
            if (!$responsibleRole) {
                throw new \Exception('Role responsible not found');
            }

            $this->updateOrCreateUserInfo($user, self::KEY_PHONE, $request->phone);
            $this->updateOrCreateUserInfo($user, self::KEY_ADDRESS, $request->address);
            $this->updateOrCreateUserInfo($user, self::KEY_ZIPCODE, $request->zipcode);
            $this->updateOrCreateUserInfo($user, self::KEY_CITY, $request->city);

            $family->userRoles()->firstOrCreate([
                'user_id' => $user->id,
                'role_id' => $responsibleRole->id,
            ]);

            if ($request->is_student) {
                $studentRole = Role::where('slug', 'student')->first();

                if ($studentRole) {
                    $family->userRoles()->firstOrCreate([
                        'user_id' => $user->id,
                        'role_id' => $studentRole->id,
                    ]);

                    $this->updateOrCreateUserInfo($user, self::KEY_BIRTHDATE, $request->birthdate);
                    $this->updateOrCreateUserInfo($user, self::KEY_GENDER, $request->gender);
                }
            }

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Responsable ajouté à la famille avec succès',
                'data' => [
                    'family_id' => $family->id,
                    'user' => $user
                ]
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'status' => 'error',
                'message' => 'Une erreur est survenue lors de l\'ajout du responsable',
                'error' => 'Une erreur est survenue'
            ], 500);
        }
    }

    public function updateResponsible(Family $family, User $responsible, Request $request)
    {
        if (!self::callerCanAccessFamily($family)) return $this->denyFamilyAccess($family);
        if ($deny = $this->ensureMemberOfFamily($responsible, $family, 'responsible')) return $deny;

        $request->validate([
            'lastname' => 'required|string|max:255',
            'firstname' => 'required|string|max:255',
            'phone' => 'required|string',
            'address' => 'required|string',
            'email' => 'required|email|unique:users,email,' . $responsible->id,
            'zipcode' => 'required|string',
            'city' => 'required|string',
            'is_student' => 'boolean',
            'birthdate' => 'required_if:is_student,true|nullable|date',
            'gender' => 'required_if:is_student,true|nullable|in:M,F',
        ], [
            'lastname.required' => 'Le nom est requis.',
            'lastname.string' => 'Le nom doit être une chaîne de caractères.',
            'lastname.max' => 'Le nom ne peut pas dépasser 255 caractères.',

            'firstname.required' => 'Le prénom est requis.',
            'firstname.string' => 'Le prénom doit être une chaîne de caractères.',
            'firstname.max' => 'Le prénom ne peut pas dépasser 255 caractères.',

            'email.required' => 'L\'adresse email est requise.',
            'email.email' => 'L\'adresse email doit être une adresse valide.',
            'email.unique' => 'L\'adresse email est déjà utilisée.',

            'phone.required' => 'Le numéro de téléphone est requis.',
            'phone.string' => 'Le numéro de téléphone doit être une chaîne de caractères.',

            'address.required' => 'L\'adresse est requise.',
            'address.string' => 'L\'adresse doit être une chaîne de caractères.',

            'zipcode.required' => 'Le code postal est requis.',
            'zipcode.string' => 'Le code postal doit être une chaîne de caractères.',

            'city.required' => 'La ville est requise.',
            'city.string' => 'La ville doit être une chaîne de caractères.',

            'is_student.boolean' => 'Le champ "est étudiant" doit être vrai ou faux.',

            'birthdate.required_if' => 'La date de naissance est obligatoire pour les étudiants.',
            'birthdate.date' => 'La date de naissance doit être une date valide.',

            'gender.required_if' => 'Le genre est obligatoire pour les étudiants.',
            'gender.in' => 'Le genre doit être "M" (masculin) ou "F" (féminin).',
        ]);

        DB::beginTransaction();

        try {
            $responsible->update([
                'first_name' => $request->firstname,
                'last_name' => $request->lastname,
                'email' => $request->email,
            ]);

            $this->updateOrCreateUserInfo($responsible, self::KEY_PHONE, $request->phone);
            $this->updateOrCreateUserInfo($responsible, self::KEY_ADDRESS, $request->address);
            $this->updateOrCreateUserInfo($responsible, self::KEY_ZIPCODE, $request->zipcode);
            $this->updateOrCreateUserInfo($responsible, self::KEY_CITY, $request->city);

            $responsibleRole = Role::where('slug', 'responsible')->first();
            if (!$responsibleRole) {
                throw new \Exception('Role responsible not found');
            }

            $family->userRoles()->updateOrCreate(
                ['user_id' => $responsible->id, 'role_id' => $responsibleRole->id]
            );

            if ($request->is_student) {
                $studentRole = Role::where('slug', 'student')->first();

                if ($studentRole) {
                    $family->userRoles()->updateOrCreate([
                        'user_id' => $responsible->id,
                        'role_id' => $studentRole->id,
                    ]);

                    $this->updateOrCreateUserInfo($responsible, self::KEY_BIRTHDATE, $request->birthdate);
                    $this->updateOrCreateUserInfo($responsible, self::KEY_GENDER, $request->gender);
                }
            } else {
                $studentRole = Role::where('slug', 'student')->first();
                if ($studentRole) {
                    $family->userRoles()
                        ->where('user_id', $responsible->id)
                        ->where('role_id', $studentRole->id)
                        ->delete();
                }
            }

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Responsable mis à jour avec succès',
                'data' => [
                    'family_id' => $family->id,
                    'user' => $responsible
                ]
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'status' => 'error',
                'message' => 'Une erreur est survenue lors de la mise à jour du responsable',
                'error' => 'Une erreur est survenue'
            ], 500);
        }
    }

    private function getUserClassroom($userId)
    {
        $userRole = UserRole::where('user_id', $userId)
            ->whereHas('role', function ($query) {
                $query->where('slug', 'student');
            })
            ->where('roleable_type', 'classroom')
            ->with(['roleable'])
            ->first();

        return $userRole ? [
            'id' => $userRole->id,
            'name' => $userRole->name
        ] : null;
    }

    private function isUserResponsible($userId, $familyId)
    {
        return DB::table('user_roles')
            ->where('user_id', $userId)
            ->where('roleable_id', $familyId)
            ->where('roleable_type', 'family')
            ->whereExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('roles')
                    ->whereColumn('roles.id', 'user_roles.role_id')
                    ->where('roles.slug', 'responsible');
            })
            ->exists();
    }

    private function isUserStudent($userId, $familyId)
    {
        return DB::table('user_roles')
            ->where('user_id', $userId)
            ->where('roleable_id', $familyId)
            ->where('roleable_type', 'family')
            ->whereExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('roles')
                    ->whereColumn('roles.id', 'user_roles.role_id')
                    ->where('roles.slug', 'student');
            })
            ->exists();
    }

    private function findOrCreateResponsibleUser(Request $request): User
    {
        $user = User::where('email', $request->email)->first();

        if ($user) {
            return $user;
        }

        return User::create([
            'first_name' => $request->firstname,
            'last_name' => $request->lastname,
            'email' => $request->email,
            'password' => Hash::make($request->password ?? str()->random(12)),
            'access' => true,
        ]);
    }

    private function updateOrCreateUserInfo(User $user, $key, $value)
    {
        if (is_null($value) || $value === '') {
            return;
        }

        UserInfo::updateOrCreate(
            [
                'user_id' => $user->id,
                'key' => $key
            ],
            [
                'value' => $value
            ]
        );
    }
}
