<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\CheckPaymentCompletionJob;
use App\Models\Family;
use App\Models\LignePaiement;
use App\Models\SchoolYear;
use App\Services\FacturePdfService;
use App\Services\PaiementService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PaiementController extends Controller
{
    private const VAT_MENTIONS = [
        'association' => 'TVA non applicable — article 261, 7-1° du CGI',
        'enseignement' => 'TVA non applicable — article 261, 4-4° du CGI',
        'franchise' => 'TVA non applicable, art. 293 B du CGI',
    ];

    protected $paiementService;

    public function __construct(PaiementService $paiementService)
    {
        $this->paiementService = $paiementService;
    }

    public function facture(Family $family)
    {
        if (! FamilyController::callerCanAccessFamily($family)) {
            return response()->json(['status' => 'error', 'message' => 'Accès refusé'], 403);
        }

        $year = SchoolYear::withoutGlobalScopes()->find(currentSchoolYearId());
        if (! $year || $year->school_id !== $family->school_id) {
            return response()->json(['status' => 'error', 'message' => 'Requête invalide'], 400);
        }

        try {
            $details = $this->paiementService->getDetailsPaiement($family);
            $total = (int) round($details['montant_total']);
            $paye = (int) round($details['montant_paye']);

            if ($total <= 0 && $paye <= 0) {
                return response()->json(['status' => 'error', 'message' => 'Aucun montant à facturer pour cette famille'], 422);
            }

            $reste = max(0, $total - $paye);
            $lignes = $details['paiement']?->lignes ?? collect();
            $acquitteeLe = $reste === 0 && $lignes->isNotEmpty()
                ? $lignes->max('created_at')->format('d/m/Y')
                : null;

            $school = $family->school;
            // Dédoublonnage : un utilisateur qui est à la fois responsable ET élève
            // de la famille a deux lignes user_roles, donc la relation le renvoie en
            // double. On ne veut le « Facturé à » qu'une seule fois par personne.
            $responsables = $family->responsibles()->with('infos')->get()->unique('id')->values();
            $names = $responsables
                ->map(fn ($responsable) => trim($responsable->first_name.' '.$responsable->last_name))
                ->filter()
                ->values()
                ->all();

            $adresse = ['address' => null, 'zipcode' => null, 'city' => null];
            foreach ($responsables as $responsable) {
                $infos = $responsable->infos->pluck('value', 'key');
                if ($infos->get('address') || $infos->get('city')) {
                    $adresse = [
                        'address' => $infos->get('address'),
                        'zipcode' => $infos->get('zipcode'),
                        'city' => $infos->get('city'),
                    ];
                    break;
                }
            }

            $numero = 'F'.$year->id.'-'.str_pad((string) $family->id, 4, '0', STR_PAD_LEFT);

            return FacturePdfService::download('facture_'.$numero, [
                'numero' => $numero,
                'date' => now()->format('d/m/Y'),
                'year_label' => $year->label,
                'school' => [
                    'name' => $school->name,
                    'address' => $school->address,
                    'zipcode' => $school->zipcode,
                    'city' => $school->city,
                    'email' => $school->email,
                    'phone' => $school->phone,
                    'siret' => $school->siret,
                    'vat_number' => $school->vat_number,
                ],
                'client' => [
                    'names' => $names ?: [$details['tarifs']['nom_famille'] ?? 'Famille'],
                    ...$adresse,
                ],
                'nombre_eleves' => (int) ($details['tarifs']['nombre_eleves'] ?? 0),
                'total' => $total,
                'paye' => $paye,
                'reste' => $reste,
                'assujetti' => $school->vat_mode === 'assujetti',
                'acquittee' => $reste === 0,
                'acquittee_le' => $acquitteeLe,
                'vat_mention' => self::VAT_MENTIONS[$school->vat_mode] ?? null,
            ]);
        } catch (\Exception $e) {
            Log::error('Facture PDF generation failed', [
                'family_id' => $family->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Une erreur est survenue',
            ], 500);
        }
    }

    public function show(Family $family)
    {
        if (! FamilyController::callerCanAccessFamily($family)) {
            return response()->json(['status' => 'error', 'message' => 'Accès refusé'], 403);
        }
        try {
            $details = $this->paiementService->getDetailsPaiement($family);

            return response()->json([
                'status' => 'success',
                'data' => $details,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors de la récupération des détails de paiement',
                'error' => 'Une erreur est survenue',
            ], 500);
        }
    }

    public function ajouterLigne(Request $request, Family $family)
    {
        if (! FamilyController::callerCanAccessFamily($family)) {
            return response()->json(['status' => 'error', 'message' => 'Accès refusé'], 403);
        }
        $request->validate([
            'type' => 'required|in:espece,carte,cheque,exoneration',
            'montant' => 'required|integer|min:1',
            'cheque' => 'required_if:type,cheque|array',
            'cheque.banque' => 'required_if:type,cheque|string',
            'cheque.numero' => 'required_if:type,cheque|string',
            'cheque.nom_emetteur' => 'required_if:type,cheque|string',
            'justification' => 'required_if:type,exoneration|string',
        ]);

        try {
            return \Illuminate\Support\Facades\DB::transaction(function () use ($request, $family) {
                Family::lockForUpdate()->find($family->id);
                $details = $this->paiementService->getDetailsPaiement($family);
                $previousResteAPayer = $details['reste_a_payer'];
                $nouveauMontantPaye = $details['montant_paye'] + $request->montant;

                if ($nouveauMontantPaye > $details['montant_total']) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Le montant total payé ne peut pas dépasser le montant dû',
                    ], 422);
                }

                $paiement = $this->paiementService->getOrCreatePaiement($family, $request->user()->id);
                $ligne = $this->paiementService->ajouterLignePaiement($paiement, $request->all());

                CheckPaymentCompletionJob::dispatch($family, $previousResteAPayer)->afterCommit();

                return response()->json([
                    'status' => 'success',
                    'message' => 'Paiement ajouté avec succès',
                    'data' => [
                        'ligne' => $ligne,
                        'nouveau_total' => $nouveauMontantPaye,
                        'reste_a_payer' => $details['montant_total'] - $nouveauMontantPaye,
                    ],
                ]);
            });
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Paiement.ajouterLigne failed', ['exception' => $e]);

            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors de l\'ajout du paiement',
            ], 500);
        }
    }

    public function modifierLigne(Request $request, Family $family, LignePaiement $ligne)
    {
        if (! FamilyController::callerCanAccessFamily($family)) {
            return response()->json(['status' => 'error', 'message' => 'Accès refusé'], 403);
        }
        if ($ligne->paiement->family_id !== $family->id) {
            return response()->json([
                'status' => 'error',
                'message' => 'Ligne de paiement non trouvée',
            ], 404);
        }

        $typePaiement = $ligne->type_paiement;

        $rules = [
            'montant' => 'required|integer|min:1',
        ];

        if ($typePaiement === 'cheque') {
            $rules['cheque'] = 'required|array';
            $rules['cheque.banque'] = 'required|string';
            $rules['cheque.numero'] = 'required|string';
            $rules['cheque.nom_emetteur'] = 'required|string';
        } elseif ($typePaiement === 'exoneration') {
            $rules['justification'] = 'required|string';
        }

        $request->validate($rules);

        try {
            $details = $this->paiementService->getDetailsPaiement($family);
            $previousResteAPayer = $details['reste_a_payer'];
            $nouveauMontantPaye = $details['montant_paye'] - $ligne->montant + $request->montant;

            if ($nouveauMontantPaye > $details['montant_total']) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Le montant total payé ne peut pas dépasser le montant dû',
                ], 422);
            }

            $ligne = $this->paiementService->modifierLignePaiement($ligne, $request->all());

            CheckPaymentCompletionJob::dispatch($family, $previousResteAPayer)->afterCommit();

            return response()->json([
                'status' => 'success',
                'message' => 'Paiement modifié avec succès',
                'data' => [
                    'ligne' => $ligne,
                    'nouveau_total' => $nouveauMontantPaye,
                    'reste_a_payer' => $details['montant_total'] - $nouveauMontantPaye,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors de la modification du paiement',
                'error' => 'Une erreur est survenue',
            ], 500);
        }
    }

    public function supprimerLigne(Request $request, Family $family, LignePaiement $ligne)
    {
        if (! FamilyController::callerCanAccessFamily($family)) {
            return response()->json(['status' => 'error', 'message' => 'Accès refusé'], 403);
        }
        if ($ligne->paiement->family_id !== $family->id) {
            return response()->json([
                'status' => 'error',
                'message' => 'Ligne de paiement non trouvée',
            ], 404);
        }

        try {
            $details = $this->paiementService->getDetailsPaiement($family);
            $previousResteAPayer = $details['reste_a_payer'];

            $montantSupprime = $ligne->montant;
            $this->paiementService->supprimerLignePaiement($ligne);

            $details = $this->paiementService->getDetailsPaiement($family);

            CheckPaymentCompletionJob::dispatch($family, $previousResteAPayer)->afterCommit();

            return response()->json([
                'status' => 'success',
                'message' => 'Paiement supprimé avec succès',
                'data' => [
                    'nouveau_total' => $details['montant_paye'],
                    'reste_a_payer' => $details['reste_a_payer'],
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors de la suppression du paiement',
                'error' => 'Une erreur est survenue',
            ], 500);
        }
    }
}
