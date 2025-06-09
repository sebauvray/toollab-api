<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateClassroomRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => 'sometimes|required|string|max:255',
            'years' => 'sometimes|required|integer',
            'type' => 'nullable|string|max:255',
            'size' => 'sometimes|required|integer|min:1',
            'cursus_id' => 'sometimes|required|exists:cursus,id',
            'level_id' => 'nullable|exists:cursus_levels,id',
            'gender' => 'sometimes|required|in:Hommes,Femmes,Enfants,Mixte',
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Le nom de la classe est obligatoire.',
            'name.string' => 'Le nom doit être une chaîne de caractères.',
            'name.max' => 'Le nom ne peut pas dépasser 255 caractères.',

            'years.required' => 'L\'année scolaire est obligatoire.',
            'years.integer' => 'L\'année scolaire doit être un nombre entier.',

            'size.required' => 'La capacité de la classe est obligatoire.',
            'size.integer' => 'La capacité doit être un nombre entier.',
            'size.min' => 'La capacité doit être d\'au moins 1.',

            'cursus_id.required' => 'L\'identifiant du cursus est obligatoire.',
            'cursus_id.exists' => 'Le cursus spécifié n\'existe pas.',

            'level_id.exists' => 'Le niveau spécifié n\'existe pas.',

            'gender.required' => 'Le genre est obligatoire.',
            'gender.in' => 'Le genre doit être l\'un des suivants : Hommes, Femmes, Enfants, Mixte.',
        ];
    }
}
