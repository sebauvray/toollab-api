<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Family;
use App\Models\Role;
use App\Models\User;
use App\Models\UserInfo;
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
                    'city' => $userInfos[self::KEY_CITY] ?? null,
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
            'email' => 'required|email:rfc,dns|max:255',
            'phone' => 'nullable|string|max:20',
            'address' => 'nullable|string|max:255',
            'zipcode' => 'nullable|string|max:20',
            'city' => 'nullable|string|max:255',
            'is_student' => 'boolean',
            'birthdate' => 'nullable|date|required_if:is_student,true'
        ], [
            'email.email' => 'L\'adresse e-mail saisie n\'est pas valide.',
        ]);

        $user = User::where('email', $request->email)->first();
        $responsibleRole = Role::where('slug', 'responsible')->first();

        DB::beginTransaction();
        try {
            if ($user) {
                $isAlreadyResponsible = $user->roles()
                    ->whereHas('role', function ($query) {
                        $query->where('slug', 'responsible');
                    })
                    ->exists();

                if ($isAlreadyResponsible) {
                    DB::rollBack();
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Cette adresse email est déjà associée à un responsable de famille'
                    ], 422);
                }
            } else {
                $user = User::create([
                    'first_name' => $request->firstname,
                    'last_name' => $request->lastname,
                    'email' => $request->email,
                    'password' => Hash::make(uniqid()),
                    'access' => false,
                ]);
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
            ->with(['user', 'user.infos', 'role'])
            ->whereHas('role', function ($query) {
                $query->where('slug', 'student');
            })
            ->get()
            ->map(function ($userRole) {
                $user = $userRole->user;

                $userInfos = collect($user->infos)->pluck('value', 'key')->toArray();

                return [
                    'id' => $user->id,
                    'first_name' => $user->first_name,
                    'last_name' => $user->last_name,
                    'birthdate' => $userInfos[self::KEY_BIRTHDATE] ?? null,
                    'is_responsible' => $this->isUserResponsible($user->id, $userRole->roleable_id),
                    'role' => $userRole->role->name,
                    'classroom' => $this->getUserClassroom($user->id),
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
        } elseif ($request->has('author_name')) {
            $authorName = $request->author_name;
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Commentaire ajouté avec succès',
            'data' => [
                'comment' => [
                    'id' => $comment->id,
                    'content' => $comment->content,
                    'author' => $authorName,
                    'date' => $comment->created_at->format('d/m/Y'),
                    'created_at' => $comment->created_at
                ]
            ]
        ]);
    }
    public function addStudents(Request $request, Family $family)
    {
        $request->validate([
            'students' => 'required|array',
            'students.*.firstname' => 'required|string|max:255',
            'students.*.lastname' => 'required|string|max:255',
            'students.*.birthdate' => 'required|date',
            'students.*.gender' => 'required|in:M,F'
        ]);

        $studentRole = Role::where('slug', 'student')->first();

        if (!$studentRole) {
            return response()->json([
                'status' => 'error',
                'message' => 'Le rôle d\'élève n\'existe pas'
            ], 500);
        }

        $addedStudents = [];

        DB::beginTransaction();
        try {
            foreach ($request->students as $studentData) {
                $student = User::create([
                    'first_name' => $studentData['firstname'],
                    'last_name' => $studentData['lastname'],
                    'email' => 'student_' . uniqid() . '@example.com',
                    'password' => Hash::make(uniqid()),
                    'access' => false,
                ]);

                $this->updateOrCreateUserInfo($student, self::KEY_BIRTHDATE, $studentData['birthdate']);

                $this->updateOrCreateUserInfo($student, 'gender', $studentData['gender']);

                $family->userRoles()->create([
                    'user_id' => $student->id,
                    'role_id' => $studentRole->id,
                ]);

                $addedStudents[] = $student;
            }

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => count($addedStudents) . ' élève(s) ajouté(s) avec succès',
                'data' => [
                    'students' => $addedStudents
                ]
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

        $responsibleRole = Role::where('slug', 'responsible')->first();

        if (!$responsibleRole) {
            return response()->json([
                'status' => 'error',
                'message' => 'Le rôle de responsable n\'existe pas'
            ], 500);
        }

        $user = User::find($request->user_id);

        $isAlreadyResponsible = $family->userRoles()
            ->where('user_id', $user->id)
            ->where('role_id', $responsibleRole->id)
            ->exists();

        if ($isAlreadyResponsible) {
            return response()->json([
                'status' => 'error',
                'message' => 'Cet utilisateur est déjà responsable de cette famille'
            ], 422);
        }

        $family->userRoles()->create([
            'user_id' => $user->id,
            'role_id' => $responsibleRole->id,
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Responsable ajouté avec succès',
            'data' => [
                'user' => $user
            ]
        ]);
    }
    private function getUserClassroom($userId)
    {
        $userRole = DB::table('user_roles')
            ->where('user_id', $userId)
            ->where('roleable_type', 'classroom')
            ->join('classrooms', 'user_roles.roleable_id', '=', 'classrooms.id')
            ->select('classrooms.id', 'classrooms.name')
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
