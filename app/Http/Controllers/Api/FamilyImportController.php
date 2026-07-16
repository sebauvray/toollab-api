<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessFamilyImportJob;
use App\Models\FamilyImport;
use App\Services\ExportService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class FamilyImportController extends Controller
{
    private const HEADERS = [
        'Référence famille',
        'Nom élève',
        'Prénom élève',
        'Date de naissance',
        'Genre',
        'Nom responsable 1',
        'Prénom responsable 1',
        'Email responsable 1',
        'Téléphone responsable 1',
        'Adresse responsable 1',
        'Code postal responsable 1',
        'Ville responsable 1',
        'Nom responsable 2',
        'Prénom responsable 2',
        'Email responsable 2',
        'Téléphone responsable 2',
        'Adresse responsable 2',
        'Code postal responsable 2',
        'Ville responsable 2',
        'Élève est son propre responsable',
    ];

    /**
     * Génère et télécharge le modèle Excel prérempli d'une famille d'exemple.
     */
    public function template()
    {
        // Deux parents, répétés à l'identique sur chaque ligne de la fratrie.
        $benaliResp2 = ['Benali', 'Sofia', 'sofia.benali@email.com', '0698765432', '12 rue des Lilas', '75011', 'Paris'];
        $benali = fn ($first, $date, $g) => array_merge(
            ['FAM001', 'Benali', $first, $date, $g],
            ['Benali', 'Karim', 'karim.benali@email.com', '0612345678', '12 rue des Lilas', '75011', 'Paris'],
            $benaliResp2,
            ['Non']
        );

        // Un seul responsable (Responsable 2 laissé vide car optionnel).
        $haddad = fn ($first, $date, $g) => array_merge(
            ['FAM002', 'Haddad', $first, $date, $g],
            ['Haddad', 'Nadia', 'nadia.haddad@email.com', '0655443322', '5 av. Victor Hugo', '69003', 'Lyon'],
            ['', '', '', '', '', '', ''],
            ['Non']
        );

        $example = [
            // Famille de 5 élèves, deux responsables : chaque ligne est complète.
            $benali('Yasmine', '2015-03-12', 'F'),
            $benali('Adam', '2017-09-05', 'M'),
            $benali('Sarah', '2012-01-20', 'F'),
            $benali('Rayan', '2019-11-08', 'M'),
            $benali('Nour', '2021-04-17', 'F'),

            // Famille avec un seul responsable.
            $haddad('Lina', '2014-06-30', 'F'),
            $haddad('Yanis', '2016-02-11', 'M'),

            // L'élève est son propre responsable : Responsable 1 = lui-même, entièrement rempli.
            ['FAM003', 'Cherif', 'Sofiane', '2003-08-25', 'M',
                'Cherif', 'Sofiane', 'sofiane.cherif@email.com', '0611223344', '18 rue Nationale', '59000', 'Lille',
                '', '', '', '', '', '', '', 'Oui'],
        ];

        return ExportService::xlsx('modele_import_eleves', self::HEADERS, $example);
    }

    /**
     * Importe un fichier .xlsx/.csv d'élèves et responsables.
     */
    public function import(Request $request)
    {
        $request->validate([
            'file' => 'required|file|max:10240',
        ], [
            'file.required' => 'Aucun fichier fourni.',
            'file.file' => 'Le fichier fourni est invalide.',
            'file.max' => 'Le fichier ne doit pas dépasser 10 Mo.',
        ]);

        $upload = $request->file('file');
        $extension = strtolower($upload->getClientOriginalExtension());

        if (! in_array($extension, ['xlsx', 'csv'], true)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Format non supporté. Utilisez un fichier .xlsx ou .csv.',
            ], 422);
        }

        $storedPath = $upload->storeAs(
            'family-imports',
            Str::uuid()->toString().'.'.$extension,
            'local'
        );

        $import = FamilyImport::query()->create([
            'school_id' => currentSchoolId(),
            'school_year_id' => currentSchoolYearId(),
            'user_id' => $request->user()?->id,
            'original_filename' => $upload->getClientOriginalName(),
            'stored_path' => $storedPath,
            'status' => 'pending',
            'message' => 'Import en attente de traitement.',
        ]);

        ProcessFamilyImportJob::dispatch($import->id);

        return response()->json($this->serializeImport($import), 202);
    }

    public function show(FamilyImport $familyImport)
    {
        if ($familyImport->school_id !== currentSchoolId()) {
            return response()->json(['message' => 'Import introuvable.'], 404);
        }

        return response()->json($this->serializeImport($familyImport));
    }

    private function serializeImport(FamilyImport $import): array
    {
        return [
            'id' => $import->id,
            'status' => $import->status,
            'message' => $import->message,
            'summary' => $import->summary,
            'errors' => $import->errors ?? [],
            'error_count' => $import->error_count,
            'errors_truncated' => $import->errors_truncated,
            'errors_limit' => $import->errors_limit,
            'created_at' => $import->created_at,
            'started_at' => $import->started_at,
            'finished_at' => $import->finished_at,
        ];
    }
}
