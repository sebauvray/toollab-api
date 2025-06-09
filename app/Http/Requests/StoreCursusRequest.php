<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreCursusRequest extends FormRequest
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
            'name' => 'required|string|max:255',
            'progression' => 'required|in:levels,continu',
            'school_id' => 'required|exists:schools,id',
            'levels' => 'required|array',
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
            'progression.in' => 'Le type de progression doit être soit "levels" soit "continu".',

            'levels.required' => 'Au moins un niveau est requis.',
            'levels.array' => 'Les niveaux doivent être fournis sous forme de tableau.',

            'levels.*.name.required' => 'Le nom du niveau est obligatoire.',
            'levels.*.name.string' => 'Le nom du niveau doit être une chaîne de caractères.',
            'levels.*.name.max' => 'Le nom du niveau ne peut pas dépasser 255 caractères.',
        ];
    }
}
