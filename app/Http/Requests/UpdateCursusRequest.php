<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCursusRequest extends FormRequest
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
            'progression' => 'sometimes|required|in:levels,continu',
            'levels' => 'sometimes|required|array',
            'levels.*.id' => 'sometimes|exists:cursus_levels,id',
            'levels.*.name' => 'required|string|max:255',
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
            'name.required' => 'Le nom du cursus est obligatoire.',
            'name.string' => 'Le nom doit être une chaîne de caractères.',
            'name.max' => 'Le nom ne peut pas dépasser 255 caractères.',

            'progression.required' => 'Le type de progression est obligatoire.',

            'levels.required' => 'Au moins un niveau est requis.',

            'levels.*.name.required' => 'Le nom du niveau est obligatoire.',
            'levels.*.name.string' => 'Le nom du niveau doit être une chaîne de caractères.',
            'levels.*.name.max' => 'Le nom du niveau ne peut pas dépasser 255 caractères.',
        ];
    }
}
