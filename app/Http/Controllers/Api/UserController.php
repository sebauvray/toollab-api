<?php

namespace App\Http\Controllers\Api;

use App\Models\Classroom;
use App\Models\User;
use App\Models\School;
use App\Models\UserInfo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRequest;

class UserController extends Controller
{

    /**
     * Format roles for a specific context type
     */
    /**
     * Format roles for a specific context type
     */
    private function formatRoles(User $user, string $type): array
    {
        $userRoles = $user->roles->where('roleable_type', $type);

        // withoutGlobalScopes : l'endpoint /users/{id}/roles est appelé AVANT
        // qu'une école soit sélectionnée, donc le scope BelongsToSchool filtrerait
        // tout à 0 — on le contourne pour lister toutes les appartenances.
        $modelMap = [
            'school' => \App\Models\School::class,
            'family' => \App\Models\Family::class,
            'classroom' => \App\Models\Classroom::class,
        ];

        $contextsByType = [];
        $modelClass = $modelMap[$type] ?? null;
        if ($modelClass) {
            $ids = $userRoles->pluck('roleable_id')->unique()->all();
            $contextsByType = $modelClass::query()
                ->when(method_exists($modelClass, 'addGlobalScope'), function ($q) {
                    return $q->withoutGlobalScopes();
                })
                ->whereIn('id', $ids)
                ->get()
                ->keyBy('id');
        }

        return $userRoles
            ->map(function ($userRole) use ($type, $contextsByType) {
                $context = $contextsByType[$userRole->roleable_id] ?? null;
                $contextName = $context?->name ?? 'Sans nom';

                if ($type === 'family') {
                    $responsibleUser = User::whereHas('roles', function ($query) use ($userRole) {
                        $query->where('roleable_id', $userRole->roleable_id)
                            ->where('roleable_type', 'family')
                            ->whereHas('role', function ($q) {
                                $q->where('slug', 'responsible');
                            });
                    })->first();

                    if ($responsibleUser) {
                        $contextName = 'Famille ' . $responsibleUser->last_name;
                    }
                }

                $contextData = [
                    'id' => $userRole->roleable_id,
                    'name' => $contextName,
                    'type' => class_basename($userRole->roleable_type),
                ];

                if ($type === 'school' && $context && !empty($context->logo)) {
                    $contextData['logo'] = $context->logo;
                }

                return [
                    'role' => $userRole->role->name,
                    'context' => $contextData,
                ];
            })
            ->values()
            ->toArray();
    }

    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $schoolId = currentSchoolId();
        if ($schoolId === null) {
            return response()->json(['message' => 'Requête invalide'], 400);
        }

        $familyIds = \App\Models\Family::query()->withoutGlobalScopes()
            ->where('school_id', $schoolId)->pluck('id');
        $classroomIds = \App\Models\Classroom::query()->withoutGlobalScopes()
            ->where('school_id', $schoolId)->pluck('id');

        return User::whereHas('roles', function ($q) use ($schoolId, $familyIds, $classroomIds) {
            $q->where(function ($q2) use ($schoolId, $familyIds, $classroomIds) {
                $q2->where(function ($q3) use ($schoolId) {
                    $q3->where('roleable_type', 'school')->where('roleable_id', $schoolId);
                })
                ->orWhere(function ($q3) use ($familyIds) {
                    $q3->where('roleable_type', 'family')->whereIn('roleable_id', $familyIds);
                })
                ->orWhere(function ($q3) use ($classroomIds) {
                    $q3->where('roleable_type', 'classroom')->whereIn('roleable_id', $classroomIds);
                });
            });
        })->get();
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreUserRequest $request)
    {
        $validatedData = $request->validated();

        return User::create([
            'first_name' => $validatedData['first_name'],
            'last_name' => $validatedData['last_name'],
            'email' => $validatedData['email'],
            'password' => bcrypt($validatedData['password']),
            'access' => true,
        ]);
    }

    public function update(UpdateUserRequest $request, User $user)
    {
        $validatedData = $request->validated();

        $dataToUpdate = [
            'first_name' => $validatedData['first_name'],
            'last_name' => $validatedData['last_name'],
            'email' => $validatedData['email'],
        ];

        if (!empty($validatedData['password'])) {
            $dataToUpdate['password'] = bcrypt($validatedData['password']);
        }

        $user->update($dataToUpdate);

        return $user;
    }

    /**
     * Display the specified resource.
     */
    public function show(User $user)
    {
        return $user->load('roles');
    }

    /**
     * Update the specified resource in storage.
     */

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(User $user): void
    {
        $user::findOrFail($user->id)->delete();
    }


    /**
     * Get all users with their roles in all contexts
     */
    public function getAllUsersWithRoles()
    {
        $users = User::with(['roles.role', 'roles.roleable'])->get()
            ->map(function ($user) {
                return [
                    'id' => $user->id,
                    'first_name' => $user->first_name,
                    'last_name' => $user->last_name,
                    'email' => $user->email,
                    'roles' => [
                        'schools' => $this->formatRoles($user, 'school'),
                        'families' => $this->formatRoles($user, 'family'),
                        'classrooms' => $this->formatRoles($user, 'classroom'),
                    ]
                ];
            });

        return $users;
    }

    /**
     * Mettre à jour les informations d'un utilisateur
     */
    public function updateUserInfo(Request $request, User $user)
    {
        $request->validate([
            'phone' => 'nullable|string|max:20',
            'address' => 'nullable|string|max:255',
            'zipcode' => 'nullable|string|max:20',
            'city' => 'nullable|string|max:255',
            'birthdate' => 'nullable|date',
        ]);

        $validKeys = ['phone', 'address', 'zipcode', 'city', 'birthdate'];

        foreach ($validKeys as $key) {
            if ($request->has($key)) {
                UserInfo::updateOrCreate(
                    [
                        'user_id' => $user->id,
                        'key' => $key
                    ],
                    [
                        'value' => $request->input($key)
                    ]
                );
            }
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Informations utilisateur mises à jour avec succès',
            'data' => [
                'user' => $user->load('infos')
            ]
        ]);
    }

    /**
     * Get the roles of a specific user
     */
    public function getUserRoles(User $user)
    {
        return [
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ],
            'roles' => [
                'schools' => $this->formatRoles($user, 'school'),
                'families' => $this->formatRoles($user, 'family'),
                'classrooms' => $this->formatRoles($user, 'classroom'),
            ]
        ];
    }

    /**
     * Get users by context type and role
     */
    public function getUsersByContextAndRole(Request $request)
    {
        $request->validate([
            'context_type' => 'required|in:school,family,classroom',
            'context_id' => 'required|integer',
            'role_name' => 'required|string'
        ]);

        return User::whereHas('roles', function ($query) use ($request) {
            $query->whereHas('role', function ($q) use ($request) {
                $q->where('name', $request->role_name);
            })
            ->where('roleable_type', $request->context_type)
            ->where('roleable_id', $request->context_id);
        })->get();
    }

    /**
     * Get users of a specific class
     */
    public function getClassroomUsers(Classroom $classroom): JsonResponse
    {
        $users = $classroom->userRoles()
            ->with(['user', 'role'])
            ->get()
            ->map(function ($userRole) {
                return [
                    'user' => [
                        'id' => $userRole->user->id,
                        'name' => $userRole->user->name,
                        'email' => $userRole->user->email,
                    ],
                    'role' => [
                        'id' => $userRole->role->id,
                        'name' => $userRole->role->name
                    ]
                ];
            });

        return [
            'classroom' => [
                'id' => $classroom->id,
                'name' => $classroom->name,
            ],
            'users' => $users
        ];
    }

    public function getSchoolUsers(School $school)
    {
        if ($school->id !== currentSchoolId()) {
            return response()->json(['message' => 'Accès refusé'], 403);
        }

        $users = $school->userRoles()
            ->with(['user', 'role'])
            ->get();

        return $users
            ->map(function ($userRole) {
                return [
                    'user' => $userRole->user,
                    'role' => $userRole->role->slug
                ];
            });
    }

    public function searchStudents(Request $request)
    {
        $request->validate([
            'query' => 'required|string|min:2|max:50'
        ]);

        $schoolId = currentSchoolId();
        if ($schoolId === null) {
            return response()->json(['message' => 'Requête invalide'], 400);
        }

        $query = $request->input('query');

        try {
            $familyIds = \App\Models\Family::query()->withoutGlobalScopes()
                ->where('school_id', $schoolId)->pluck('id');

            $students = User::select([
                'users.id',
                'users.first_name',
                'users.last_name',
                'user_infos.value as birthdate',
                'user_roles.roleable_id as family_id'
            ])
                ->join('user_roles', 'users.id', '=', 'user_roles.user_id')
                ->join('roles', 'user_roles.role_id', '=', 'roles.id')
                ->leftJoin('user_infos', function($join) {
                    $join->on('users.id', '=', 'user_infos.user_id')
                        ->where('user_infos.key', '=', 'birthdate');
                })
                ->where('roles.slug', 'student')
                ->where('user_roles.roleable_type', 'family')
                ->whereIn('user_roles.roleable_id', $familyIds)
                ->where(function($q) use ($query) {
                    $q->where('users.first_name', 'LIKE', "%{$query}%")
                        ->orWhere('users.last_name', 'LIKE', "%{$query}%")
                        ->orWhereRaw("CONCAT(users.first_name, ' ', users.last_name) LIKE ?", ["%{$query}%"])
                        ->orWhereRaw("CONCAT(users.last_name, ' ', users.first_name) LIKE ?", ["%{$query}%"])
                        ->orWhere('user_infos.value', 'LIKE', "%{$query}%")
                        ->orWhereRaw("DATE_FORMAT(user_infos.value, '%d/%m') LIKE ?", ["%{$query}%"])
                        ->orWhereRaw("DATE_FORMAT(user_infos.value, '%d/%m/%Y') LIKE ?", ["%{$query}%"]);
                })
                ->orderBy('users.first_name')
                ->orderBy('users.last_name')
                ->limit(10)
                ->get()
                ->map(function($student) {
                    return [
                        'id' => $student->id,
                        'first_name' => $student->first_name,
                        'last_name' => $student->last_name,
                        'full_name' => $student->first_name . ' ' . $student->last_name,
                        'birthdate' => $student->birthdate,
                        'birthdate_formatted' => $student->birthdate ?
                            \Carbon\Carbon::parse($student->birthdate)->format('d/m/Y') : null,
                        'family_id' => $student->family_id,
                        'display_text' => $student->first_name . ' ' . $student->last_name .
                            ($student->birthdate ? ' - ' . \Carbon\Carbon::parse($student->birthdate)->format('d/m/Y') : '')
                    ];
                });

            return response()->json([
                'status' => 'success',
                'data' => [
                    'students' => $students,
                    'total' => $students->count()
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
                'data' => [
                    'students' => [],
                    'total' => 0
                ]
            ], 500);
        }
    }
}
