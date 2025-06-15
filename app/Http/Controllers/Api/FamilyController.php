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
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class FamilyController extends Controller
{
    use PaginationTrait;
    const KEY_PHONE = 'phone';
    const KEY_ADDRESS = 'address';
    const KEY_ZIPCODE = 'zipcode';
    const KEY_CITY = 'city';
    const KEY_BIRTHDATE = 'birthdate';

    public function index(Request $request)
    {
        $query = Family::query();

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

        $paginatedData = $this->paginateQuery($query, $request);

        $formattedItems = collect($paginatedData['items'])->map(function ($family) {
            $responsable = $family->userRoles->first();
            $user = $responsable ? $responsable->user : null;

            $userInfos = $user ? collect($user->infos)->pluck('value', 'key')->toArray() : [];

            $paymentStatuses = ['paid', 'pending', 'incomplete', 'exempted'];
            $status = $paymentStatuses[array_rand($paymentStatuses)];

            return [
                'id' => $family->id,
                'nom' => $user ? $user->first_name . ' ' . $user->last_name : 'Sans responsable',
                'nombreEleves' => $family->students_count,
                'status' => $status,
                'dateInscription' => $family->created_at->locale('fr_FR')->format('d M Y, H:i'),
                'contact' => [
                    'phone' => $userInfos[self::KEY_PHONE] ?? null,
                    'email' => $user ? $user->email : null,
                    'address' => $userInfos[self::KEY_ADDRESS] ?? null,
                    'zipcode' => $userInfos[self::KEY_ZIPCODE] ?? null,
                    'city' => $userInfos[self::KEY_CITY] ?? null
                ]
            ];
        });

        return response()->json([
            'status' => 'success',
            'data' => [
                'items' => $formattedItems,
                'pagination' => $paginatedData['pagination']
            ]
        ]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'firstname' => 'required|string|max:255',
            'lastname' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'phone' => 'nullable|string',
            'address' => 'nullable|string',
            'zipcode' => 'nullable|string',
            'city' => 'nullable|string',
            'birthdate' => 'nullable|date',
            'is_student' => 'boolean'
        ]);

        DB::beginTransaction();

        try {
            $user = User::create([
                'first_name' => $request->firstname,
                'last_name' => $request->lastname,
                'email' => $request->email,
                'password' => Hash::make($request->password ?? str()->random(12)),
                'access' => true
            ]);

            $responsibleRole = Role::where('slug', 'responsible')->first();

            if (!$responsibleRole) {
                throw new \Exception('Role responsible not found');
            }

            $this->updateOrCreateUserInfo($user, self::KEY_PHONE, $request->phone);
            $this->updateOrCreateUserInfo($user, self::KEY_ADDRESS, $request->address);
            $this->updateOrCreateUserInfo($user, self::KEY_ZIPCODE, $request->zipcode);
            $this->updateOrCreateUserInfo($user, self::KEY_CITY, $request->city);

            $family = Family::create();

            $family->userRoles()->create([
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
            return response()->json([
                'status' => 'error',
                'message' => 'Une erreur est survenue lors de la création de la famille',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function show(Family $family)
    {
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
                    'role' => $userRole->role->name
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

                $activeClassroom = $user->studentClassrooms()
                    ->where('family_id', $family->id)
                    ->where('status', 'active')
                    ->with('classroom')
                    ->first();

                return [
                    'id' => $user->id,
                    'first_name' => $user->first_name,
                    'last_name' => $user->last_name,
                    'birthdate' => $userInfos[self::KEY_BIRTHDATE] ?? null,
                    'gender' => $userInfos['gender'] ?? null,
                    'is_responsible' => $this->isUserResponsible($user->id, $userRole->roleable_id),
                    'role' => $userRole->role->name,
                    'classroom' => $activeClassroom ? $activeClassroom->classroom : null,
                    'created_at' => $userRole->created_at
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
            'data' => [
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
        $request->validate([
            'students' => 'required|array',
            'students.*.firstname' => 'required|string|max:255',
            'students.*.lastname' => 'required|string|max:255',
            'students.*.birthdate' => 'nullable|date',
            'students.*.gender' => 'required|in:M,F'
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
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function addResponsible(Request $request, Family $family)
    {
        $request->validate([
            'user_id' => 'required|exists:users,id'
        ]);

        DB::beginTransaction();

        try {
            $user = User::findOrFail($request->user_id);
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
                'error' => $e->getMessage()
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
