<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSchoolRequest;
use App\Http\Requests\UpdateSchoolRequest;
use App\Models\InvitationToken;
use App\Models\Role;
use App\Models\School;
use App\Models\SchoolYear;
use App\Models\User;
use App\Notifications\DirectorInvitation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class SchoolController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $user = auth()->user();

        if ($user->is_super_admin) {
            return School::orderBy('name')->get();
        }

        $direct = \App\Models\UserRole::where('user_id', $user->id)
            ->where('roleable_type', 'school')
            ->pluck('roleable_id');

        $familyRoleIds = \App\Models\UserRole::where('user_id', $user->id)
            ->where('roleable_type', 'family')
            ->pluck('roleable_id');
        $viaFamily = \App\Models\Family::query()->withoutGlobalScopes()
            ->whereIn('id', $familyRoleIds)
            ->pluck('school_id');

        $classroomRoleIds = \App\Models\UserRole::where('user_id', $user->id)
            ->where('roleable_type', 'classroom')
            ->pluck('roleable_id');
        $viaClassroom = \App\Models\Classroom::query()->withoutGlobalScopes()
            ->whereIn('id', $classroomRoleIds)
            ->pluck('school_id');

        $schoolIds = $direct->concat($viaFamily)->concat($viaClassroom)->unique();

        return School::whereIn('id', $schoolIds)->orderBy('name')->get();
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreSchoolRequest $request)
    {
        $validatedData = $request->validated();

        DB::beginTransaction();

        try {
            if ($request->hasFile('logo')) {
                $logoPath = $request->file('logo')->store('school_logos', 'public');
                $validatedData['logo'] = $logoPath;
            }

            $school = School::create([
                'name' => $validatedData['name'],
                'email' => $validatedData['email'] ?? null,
                'phone' => $validatedData['phone'] ?? null,
                'address' => $validatedData['address'],
                'zipcode' => $validatedData['zipcode'] ?? null,
                'city' => $validatedData['city'] ?? null,
                'country' => $validatedData['country'] ?? null,
                'logo' => $validatedData['logo'] ?? null,
                'access' => $validatedData['access'],
            ]);

            $director = User::create([
                'first_name' => $validatedData['director_first_name'],
                'last_name' => $validatedData['director_last_name'],
                'email' => $validatedData['director_email'],
                'password' => bcrypt(Str::random(32)),
                'access' => true,
            ]);

            $directorRole = Role::where('slug', 'director')->first();

            if (!$directorRole) {
                throw new \Exception('Le rôle de directeur n\'existe pas dans la base de données');
            }

            $school->userRoles()->create([
                'user_id' => $director->id,
                'role_id' => $directorRole->id,
            ]);

            // Initialise une année scolaire active : sans elle, le directeur tombe
            // sur des 409 partout dès qu'il essaie de consulter ses données.
            $now = now();
            $startYear = $now->month >= 9 ? $now->year : $now->year - 1;
            $year = new SchoolYear([
                'label' => $startYear . '-' . ($startYear + 1),
                'opened_at' => $now,
                'is_active' => true,
            ]);
            $year->school_id = $school->id;
            $year->save();

            $token = Str::random(64);

            InvitationToken::create([
                'email' => $director->email,
                'token' => $token,
                'expires_at' => now()->addDays(7),
            ]);

            $director->notify(new DirectorInvitation($school->name, $token));

            DB::commit();

            $school->director = $director;

            return response()->json($school, 201);
        } catch (\Exception $e) {
            DB::rollBack();

            if (isset($logoPath)) {
                Storage::disk('public')->delete($logoPath);
            }

            return response()->json([
                'message' => 'Une erreur est survenue lors de la création de l\'école',
                'error' => 'Une erreur est survenue'
            ], 500);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(School $school)
    {
        $user = auth()->user();
        if (!$user->is_super_admin && !$this->userHasAccessTo($user->id, $school->id)) {
            return response()->json(['message' => 'Accès refusé'], 403);
        }

        return $school;
    }

    private function userHasAccessTo(int $userId, int $schoolId): bool
    {
        if (\App\Models\UserRole::where('user_id', $userId)
            ->where('roleable_type', 'school')
            ->where('roleable_id', $schoolId)
            ->exists()) {
            return true;
        }

        $familyIds = \App\Models\Family::query()->withoutGlobalScopes()
            ->where('school_id', $schoolId)->pluck('id');
        if ($familyIds->isNotEmpty()
            && \App\Models\UserRole::where('user_id', $userId)
                ->where('roleable_type', 'family')
                ->whereIn('roleable_id', $familyIds)
                ->exists()) {
            return true;
        }

        $classroomIds = \App\Models\Classroom::query()->withoutGlobalScopes()
            ->where('school_id', $schoolId)->pluck('id');
        return $classroomIds->isNotEmpty()
            && \App\Models\UserRole::where('user_id', $userId)
                ->where('roleable_type', 'classroom')
                ->whereIn('roleable_id', $classroomIds)
                ->exists();
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateSchoolRequest $request, School $school)
    {
        $validatedData = $request->validated();

        if ($request->hasFile('logo')) {
            if ($school->logo) {
                Storage::disk('public')->delete($school->logo);
            }

            $logoPath = $request->file('logo')->store('school_logos', 'public');
            $validatedData['logo'] = $logoPath;
        }

        $school->update($validatedData);

        return response()->json($school, 200);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(School $school)
    {
        if (!auth()->user()?->is_super_admin) {
            \Illuminate\Support\Facades\Log::warning('SchoolController.destroy: forbidden', [
                'caller_id' => auth()->id(),
                'school_id' => $school->id,
            ]);
            return response()->json(['message' => 'Accès refusé'], 403);
        }

        if ($school->logo) {
            Storage::disk('public')->delete($school->logo);
        }

        $school->delete();

        return response()->json(null, 204);
    }

    public function getAllFamiliesInSchool(School $school) {
        // Implementation as needed
    }
}
