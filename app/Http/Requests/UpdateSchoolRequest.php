<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSchoolRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'sometimes|required|string|max:255',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:20',
            'address' => 'sometimes|required|string|max:255',
            'zipcode' => 'nullable|string|max:20',
            'city' => 'nullable|string|max:255',
            'country' => 'nullable|string|max:255',
            'logo' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:10000',
            'access' => 'sometimes|required|boolean',
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Le nom de l\'établissement est obligatoire.',
            'name.string' => 'Le nom doit être une chaîne de caractères.',
            'name.max' => 'Le nom ne peut pas dépasser 255 caractères.',

            'email.email' => 'L\'adresse email n\'est pas valide.',
            'email.max' => 'L\'email ne peut pas dépasser 255 caractères.',

            'phone.string' => 'Le numéro de téléphone doit être une chaîne de caractères.',
            'phone.max' => 'Le numéro de téléphone ne peut pas dépasser 20 caractères.',

            'address.required' => 'L\'adresse est obligatoire.',
            'address.string' => 'L\'adresse doit être une chaîne de caractères.',
            'address.max' => 'L\'adresse ne peut pas dépasser 255 caractères.',

            'zipcode.string' => 'Le code postal doit être une chaîne de caractères.',
            'zipcode.max' => 'Le code postal ne peut pas dépasser 20 caractères.',

            'city.string' => 'La ville doit être une chaîne de caractères.',
            'city.max' => 'La ville ne peut pas dépasser 255 caractères.',

            'country.string' => 'Le pays doit être une chaîne de caractères.',
            'country.max' => 'Le pays ne peut pas dépasser 255 caractères.',

            'logo.image' => 'Le logo doit être une image.',
            'logo.mimes' => 'Le logo doit être au format jpeg, png, jpg ou gif.',
            'logo.max' => 'Le logo ne peut pas dépasser 10 Mo.',

            'access.required' => 'Le statut d\'accès est obligatoire.',
            'access.boolean' => 'Le statut d\'accès doit être vrai ou faux.',
        ];
    }
}
