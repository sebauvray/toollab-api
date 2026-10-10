<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\School;
use App\Support\Audit;
use App\Support\Features;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class FeatureController extends Controller
{
    /** Valeurs effectives pour l'école courante (contexte école). */
    public function current(): JsonResponse
    {
        return response()->json(Features::forSchool((int) currentSchoolId()));
    }

    /** Catalogue + valeurs pour une école (super-admin). */
    public function index(School $school): JsonResponse
    {
        $values = Features::forSchool($school->id);

        return response()->json(collect(Features::CATALOG)->map(fn ($def, $key) => [
            'key' => $key,
            'label' => $def['label'],
            'description' => $def['description'],
            'default' => $def['default'],
            'enabled' => $values[$key],
        ])->values());
    }

    public function update(Request $request, School $school, string $feature): JsonResponse
    {
        $request->merge(['feature' => $feature]);
        $validated = $request->validate([
            'feature' => ['required', Rule::in(array_keys(Features::CATALOG))],
            'enabled' => 'required|boolean',
        ]);

        $before = Features::enabled($feature, $school->id);
        Features::set($school->id, $feature, $validated['enabled'], $request->user()->id);

        if ($before !== $validated['enabled']) {
            Audit::log('feature.toggled', $school->id, $school, ['feature' => Features::CATALOG[$feature]['label'], 'enabled' => $validated['enabled']]);
        }

        return $this->index($school);
    }
}
