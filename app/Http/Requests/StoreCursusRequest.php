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
            'levels_count' => 'required_if:progression,levels|integer|min:1|max:20'
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
            'name.required' => 'Le nom du cursus est requis',
            'progression.required' => 'Le type de progression est requis',
            'progression.in' => 'Le type de progression doit être "levels" ou "continu"',
            'school_id.required' => 'L\'école est requise',
            'school_id.exists' => 'L\'école sélectionnée n\'existe pas',
            'levels_count.required_if' => 'Le nombre de niveaux est requis pour un cursus par niveaux',
            'levels_count.integer' => 'Le nombre de niveaux doit être un nombre entier',
            'levels_count.min' => 'Le cursus doit avoir au moins 1 niveau',
            'levels_count.max' => 'Le cursus ne peut pas avoir plus de 20 niveaux'
        ];
    }
}
