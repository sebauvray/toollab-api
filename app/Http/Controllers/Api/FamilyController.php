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

        $query->withCount(['studentClassrooms as active_inscriptions_count' => function ($q) {
            $q->where('status', 'active');
        }]);

        $paginatedData = $this->paginateQuery($query, $request);

        $formattedItems = collect($paginatedData['items'])->flatMap(function ($family) {
            $responsables = $family->userRoles;

            if ($responsables->isEmpty()) {
                return [[
                    'id' => $family->id,
                    'nom' => 'Sans responsable',
                    'nombreEleves' => $family->students_count,
                    'status' => $this->calculatePaymentStatus($family),
                    'dateInscription' => $family->created_at->locale('fr_FR')->format('d M Y, H:i'),
                    'contact' => [
                        'phone' => null,
                        'email' => null,
                        'address' => null,
                        'zipcode' => null,
                        'city' => null
                    ]
                ]];
            }

            return $responsables->map(function ($responsable) use ($family) {
                $user = $responsable->user;
                $userInfos = $user ? collect($user->infos)->pluck('value', 'key')->toArray() : [];

                return [
                    'id' => $family->id,
                    'nom' => $user ? $user->first_name . ' ' . $user->last_name : 'Sans responsable',
                    'nombreEleves' => $family->students_count,
                    'status' => $this->calculatePaymentStatus($family),
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
        });

        return response()->json([
            'status' => 'success',
            'data' => [
                'items' => $formattedItems,
                'pagination' => $paginatedData['pagination']
            ]
        ]);
    }

    private function calculatePaymentStatus(Family $family): string
    {
        if ($family->active_inscriptions_count == 0) {
            return '';
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
            'email' => 'required|email|unique:users,email',
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
            $authenticatedUser = Auth::user();

            $userSchoolRole = UserRole::where('user_id', $authenticatedUser->id)
                ->where('roleable_type', 'school')
                ->first();

            if (!$userSchoolRole) {
                throw new \Exception('L\'utilisateur n\'est associé à aucune école');
            }

            $schoolId = $userSchoolRole->roleable_id;

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

            $family = Family::create([
                'school_id' => $schoolId
            ]);

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
            return response()->json([
                'status' => 'error',
                'message' => 'Une erreur est survenue lors de la création de la famille',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function update(Request $request, Family $family, User $user)
    {
        $request->validate([
            'lastname' => 'required|string|max:255',
            'firstname' => 'required|string|max:255',
            'phone' => 'required|string',
            'address' => 'required|string',
            'email' => 'required|email|unique:users,email,' . $user->id,
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
            $user->first_name = $request->firstname;
            $user->last_name = $request->lastname;
            $user->email = $request->email;
            $user->save();

            $this->updateOrCreateUserInfo($user, self::KEY_PHONE, $request->phone);
            $this->updateOrCreateUserInfo($user, self::KEY_ADDRESS, $request->address);
            $this->updateOrCreateUserInfo($user, self::KEY_ZIPCODE, $request->zipcode);
            $this->updateOrCreateUserInfo($user, self::KEY_CITY, $request->city);

            if ($request->is_student) {
                $this->updateOrCreateUserInfo($user, self::KEY_BIRTHDATE, $request->birthdate);
                $this->updateOrCreateUserInfo($user, self::KEY_GENDER, $request->gender);
            }

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Responsable mis à jour avec succès',
                'data' => $user->refresh()
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'status' => 'error',
                'message' => 'Une erreur est survenue lors de la mise à jour du responsable',
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
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function updateStudent(Request $request, Family $family, User $student)
    {
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
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function deleteStudent(Family $family, User $student)
    {
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

    public function addResponsibleToFamily(Family $family, Request $request)
    {
        $request->validate([
            'lastname' => 'required|string|max:255',
            'firstname' => 'required|string|max:255',
            'phone' => 'required|string',
            'address' => 'required|string',
            'email' => 'required|email|unique:users,email',
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

            $family->userRoles()->create([
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
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function updateResponsible(Family $family, User $responsible, Request $request)
    {
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
