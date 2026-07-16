<?php

namespace App\Jobs;

use App\Models\FamilyImport;
use App\Services\FamilyImportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ProcessFamilyImportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;

    public function __construct(public int $familyImportId)
    {
    }

    public function handle(FamilyImportService $service): void
    {
        $import = FamilyImport::query()->findOrFail($this->familyImportId);

        $previous = [
            request()->attributes->get('current_school_id'),
            request()->attributes->get('current_school_year_id'),
        ];

        request()->attributes->set('current_school_id', $import->school_id);
        request()->attributes->set('current_school_year_id', $import->school_year_id);

        $import->update([
            'status' => 'processing',
            'message' => 'Import en cours de traitement.',
            'started_at' => now(),
        ]);

        try {
            $path = Storage::disk('local')->path($import->stored_path);
            $report = $service->import($path, $import->original_filename);

            if (! ($report['ok'] ?? false)) {
                $import->update([
                    'status' => 'failed',
                    'message' => 'Le fichier contient des erreurs. Aucun élève n\'a été importé.',
                    'errors' => $report['errors'] ?? [],
                    'error_count' => $report['error_count'] ?? count($report['errors'] ?? []),
                    'errors_truncated' => $report['errors_truncated'] ?? false,
                    'errors_limit' => $report['errors_limit'] ?? count($report['errors'] ?? []),
                    'finished_at' => now(),
                ]);

                return;
            }

            $summary = $report['summary'];
            $import->update([
                'status' => 'completed',
                'message' => sprintf(
                    '%d famille(s), %d élève(s) et %d responsable(s) importés.',
                    $summary['families'],
                    $summary['students'],
                    $summary['responsibles_created'] + $summary['responsibles_reused']
                ),
                'summary' => $summary,
                'errors' => [],
                'error_count' => 0,
                'errors_truncated' => false,
                'errors_limit' => 0,
                'finished_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::error('FamilyImport: job failed', [
                'family_import_id' => $import->id,
                'exception' => $e->getMessage(),
                'class' => get_class($e),
            ]);

            $import->update([
                'status' => 'failed',
                'message' => 'Une erreur technique est survenue pendant l\'import. Aucun élève n\'a été importé.',
                'errors' => [[
                    'row' => 0,
                    'colonne' => '',
                    'cell' => '',
                    'valeur' => null,
                    'message' => config('app.debug')
                        ? get_class($e).' - '.$e->getMessage()
                        : 'Erreur technique pendant le traitement.',
                ]],
                'error_count' => 1,
                'errors_truncated' => false,
                'errors_limit' => 1,
                'finished_at' => now(),
            ]);
        } finally {
            Storage::disk('local')->delete($import->stored_path);
            request()->attributes->set('current_school_id', $previous[0]);
            request()->attributes->set('current_school_year_id', $previous[1]);
        }
    }
}
