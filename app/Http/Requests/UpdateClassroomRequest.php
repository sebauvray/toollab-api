<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateClassroomRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'years' => 'required|integer',
            'type' => 'nullable|string|max:255',
            'size' => 'required|integer|min:1',
            'cursus_id' => 'required|exists:cursus,id',
            'level_id' => 'nullable|exists:cursus_levels,id',
            'gender' => 'required|in:Hommes,Femmes,Enfants,Mixte',
            'telegram_link' => 'nullable|string|max:500',
            'schedules' => 'nullable|array',
            'schedules.*.id' => 'nullable|exists:class_schedules,id',
            'schedules.*.day' => 'required_with:schedules.*|in:Lundi,Mardi,Mercredi,Jeudi,Vendredi,Samedi,Dimanche',
            'schedules.*.start_time' => 'required_with:schedules.*|date_format:H:i',
            'schedules.*.end_time' => 'required_with:schedules.*|date_format:H:i|after:schedules.*.start_time',
            'schedules.*.teacher_name' => 'nullable|string|max:255',
            'schedules.*.delete' => 'nullable|boolean'
        ];
    }

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

            'telegram_link.string' => 'Le lien Telegram doit être une chaîne de caractères.',
            'telegram_link.max' => 'Le lien Telegram ne peut pas dépasser 500 caractères.',

            'schedules.array' => 'Les horaires doivent être un tableau.',
            'schedules.*.id.exists' => 'Le créneau spécifié n\'existe pas.',
            'schedules.*.day.required_with' => 'Le jour est obligatoire pour chaque créneau.',
            'schedules.*.day.in' => 'Le jour doit être un jour valide de la semaine.',
            'schedules.*.start_time.required_with' => 'L\'heure de début est obligatoire.',
            'schedules.*.start_time.date_format' => 'L\'heure de début doit être au format HH:MM.',
            'schedules.*.end_time.required_with' => 'L\'heure de fin est obligatoire.',
            'schedules.*.end_time.date_format' => 'L\'heure de fin doit être au format HH:MM.',
            'schedules.*.end_time.after' => 'L\'heure de fin doit être après l\'heure de début.',
            'schedules.*.teacher_name.string' => 'Le nom du professeur doit être une chaîne de caractères.',
            'schedules.*.teacher_name.max' => 'Le nom du professeur ne peut pas dépasser 255 caractères.'
        ];
    }
}
