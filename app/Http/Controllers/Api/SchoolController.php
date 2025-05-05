<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSchoolRequest;
use App\Http\Requests\UpdateSchoolRequest;
use App\Models\InvitationToken;
use App\Models\Role;
use App\Models\School;
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
        return School::all();
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
                'email' => $validatedData['email'],
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
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(School $school)
    {
        return $school;
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
