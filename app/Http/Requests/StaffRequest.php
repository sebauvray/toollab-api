<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

class StaffRequest extends FormRequest
{
    public function authorize()
    {
        if (auth()->user()?->is_super_admin) {
            return true;
        }

        $schoolId = $this->input('school_id');
        $requestedRoles = $this->input('roles', [$this->input('role')]);
        if (!is_array($requestedRoles)) {
            $requestedRoles = [$this->input('role')];
        }
        $requestedRoles = array_values(array_filter(array_unique($requestedRoles)));

        $userRoles = auth()->user()->roles()
            ->whereIn('role_id', function ($query) {
                $query->select('id')
                    ->from('roles')
                    ->whereIn('slug', ['director', 'admin']);
            })
            ->where('roleable_type', 'school')
            ->where('roleable_id', $schoolId)
            ->with('role')
            ->get()
            ->pluck('role.slug');

        if ($userRoles->contains('director')) {
            return true;
        }

        if (!$userRoles->contains('admin')) {
            return false;
        }

        foreach ($requestedRoles as $requestedRole) {
            if (!in_array($requestedRole, ['registar', 'teacher'], true)) {
                return false;
            }
        }

        return true;
    }

    public function rules()
    {
        return [
            'first_name' => 'nullable|string|max:255',
            'last_name' => 'nullable|string|max:255',
            'email' => 'required|email',
            'role' => 'required|in:admin,registar,teacher',
            'roles' => 'sometimes|array|min:1',
            'roles.*' => 'required|in:admin,registar,teacher|distinct',
            'school_id' => 'required|exists:schools,id',
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array
     */
    public function messages()
    {
        return [
            'first_name.required' => 'Le prénom est obligatoire.',
            'first_name.string' => 'Le prénom doit être une chaîne de caractères.',

            'last_name.required' => 'Le nom est obligatoire.',
            'last_name.string' => 'Le nom doit être une chaîne de caractères.',

            'email.required' => 'L\'adresse email est obligatoire.',
            'email.email' => 'Veuillez fournir une adresse email valide.',

            'role.required' => 'Le rôle est obligatoire.',
        ];
    }

    protected function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(
            response()->json([
                'message' => 'Les données fournies sont incorrectes',
                'errors' => $validator->errors()
            ], 422)
        );
    }

    protected function failedAuthorization()
    {
        throw new HttpResponseException(
            response()->json([
                'message' => 'Vous n\'avez pas les droits nécessaires pour effectuer cette action'
            ], 403)
        );
    }
}
