<?php

namespace App\Http\Controllers\Api;

use App\Support\Audit;
use App\Http\Controllers\Controller;
use App\Models\Classroom;
use App\Models\Family;
use App\Models\LignePaiement;
use App\Models\Scopes\BelongsToSchoolYearScope;
use App\Models\SchoolYear;
use App\Models\StudentClassroom;
use App\Models\UserRole;
use App\Services\PaiementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Suppression réversible d'une famille (corbeille) + restauration.
 *
 * Supprimer une famille = couper ses rattachements à l'école, pas détruire des
 * personnes. Concrètement, quatre ensembles de lignes passent en soft delete :
 *   1. la famille elle-même ;
 *   2. les user_roles de contexte 'family' (élèves + responsables) ;
 *   3. les inscriptions student_classrooms de l'ANNÉE COURANTE ;
 *   4. les user_roles de contexte 'classroom' correspondants — sans quoi l'élève
 *      garderait un accès à l'école via SchoolContext::userHasAccess().
 *
 * L'historique n'est pas réécrit : les inscriptions des années déjà clôturées
 * restent intactes, et la famille comme ses rattachements redeviennent visibles
 * dès qu'on consulte une année close avant la suppression
 * (voir VisibleUntilYearClosedScope).
 *
 * Intacts : users (comptes partageables entre écoles), paiements et
 * lignes_paiement (pièces comptables), comments (historique du dossier).
 *
 * La suppression est INTERDITE tant que la famille porte de l'activité sur
 * l'année courante — une inscription active ou un règlement enregistré. Voir
 * deletionBlockers().
 *
 * Toutes les lignes d'une même suppression portent le MÊME deleted_at, ce qui
 * permet à restore() de ne ressusciter que celles-là — et pas un rattachement
 * qui avait été retiré volontairement avant la suppression de la famille.
 */
class FamilyDeletionController extends Controller
{
    public function __construct(private PaiementService $paiementService)
    {
    }

    /**
     * Chiffres affichés dans la fenêtre de confirmation, avant suppression.
     * GET /families/{family}/deletion-preview
     */
    public function preview(Family $family): JsonResponse
    {
        if (! FamilyController::callerCanAccessFamily($family)) {
            return response()->json(['status' => 'error', 'message' => 'Accès refusé'], 403);
        }

        $enrollments = $this->familyEnrollments($family)->where('status', 'active');

        $classroomNames = Classroom::query()
            ->withoutGlobalScopes()
            ->whereIn('id', $enrollments->pluck('classroom_id')->unique())
            ->orderBy('name')
            ->pluck('name')
            ->all();

        $details = $this->paiementService->getDetailsPaiement($family);
        $blockers = $this->deletionBlockers($family, $enrollments->count());

        return response()->json([
            'status' => 'success',
            'data' => [
                'family_id' => $family->id,
                'family_name' => $this->familyName($family),
                'can_delete' => $blockers === [],
                'blockers' => $blockers,
                'students' => $this->countMembersWithRole($family, 'student'),
                'responsibles' => $this->countMembersWithRole($family, 'responsible'),
                'active_enrollments' => $enrollments->count(),
                'classrooms' => $classroomNames,
                'freed_spots' => $enrollments->count(),
                'montant_paye' => (int) round($details['montant_encaisse']),
                'montant_exonere' => (int) round($details['montant_exonere']),
                'reste_a_payer' => (int) round($details['reste_a_payer']),
                'comments' => $family->comments()->count(),
                'shared_users' => $this->countUsersActiveElsewhere($family),
            ],
        ]);
    }

    /**
     * DELETE /families/{family}
     */
    public function destroy(Family $family): JsonResponse
    {
        if (! FamilyController::callerCanAccessFamily($family)) {
            return response()->json(['status' => 'error', 'message' => 'Accès refusé'], 403);
        }

        // Re-vérifié ici et pas seulement dans la fenêtre de confirmation : le
        // preview date d'avant le clic, et rien n'empêche d'appeler le DELETE
        // directement.
        $activeEnrollments = $this->familyEnrollments($family)->where('status', 'active')->count();
        $blockers = $this->deletionBlockers($family, $activeEnrollments);

        if ($blockers !== []) {
            Log::warning('FamilyDeletion: destroy blocked', [
                'family_id' => $family->id,
                'caller_id' => auth()->id(),
                'blockers' => array_column($blockers, 'code'),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Cette famille ne peut pas être supprimée : elle a de l’activité sur l’année en cours.',
                'data' => ['blockers' => $blockers],
            ], 409);
        }

        $now = now();

        try {
            DB::transaction(function () use ($family, $now) {
                $enrollments = $this->familyEnrollments($family);
                $classroomIds = $enrollments->pluck('classroom_id')->unique()->all();
                $studentIds = $enrollments->pluck('student_id')->unique()->all();

                // 4. Rôles de contexte classe. D'abord, car on a besoin des
                // inscriptions encore vivantes pour savoir quelles classes viser.
                if ($classroomIds !== [] && $studentIds !== []) {
                    UserRole::query()
                        ->where('roleable_type', 'classroom')
                        ->whereIn('roleable_id', $classroomIds)
                        ->whereIn('user_id', $studentIds)
                        ->update(['deleted_at' => $now]);
                }

                // 3. Inscriptions de l'ANNÉE COURANTE uniquement (le global scope
                // BelongsToSchoolYear s'applique). Les inscriptions des années
                // déjà clôturées sont de l'historique : les couper reviendrait à
                // effacer rétroactivement un élève d'une classe où il était bien
                // présent. Elles restent donc intactes et visibles en archive.
                StudentClassroom::query()
                    ->where('family_id', $family->id)
                    ->update(['deleted_at' => $now]);

                // 2. Rattachements élèves + responsables à la famille.
                UserRole::query()
                    ->where('roleable_type', 'family')
                    ->where('roleable_id', $family->id)
                    ->update(['deleted_at' => $now]);

                // 1. La famille.
                Family::query()
                    ->whereKey($family->id)
                    ->update(['deleted_at' => $now]);
            });
        } catch (\Throwable $e) {
            Log::error('FamilyDeletion: destroy failed', [
                'family_id' => $family->id,
                'caller_id' => auth()->id(),
                'exception' => $e->getMessage(),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Une erreur est survenue lors de la suppression de la famille',
            ], 500);
        }

        Log::info('FamilyDeletion: family soft deleted', [
            'family_id' => $family->id,
            'school_id' => $family->school_id,
            'caller_id' => auth()->id(),
            'deleted_at' => $now->toDateTimeString(),
        ]);

        Audit::log('family.deleted', $family->school_id, $family);

        return response()->json([
            'status' => 'success',
            'message' => 'Famille supprimée. Vous pouvez annuler depuis la corbeille.',
        ]);
    }

    /**
     * GET /families/trashed — la corbeille de l'ANNÉE CONSULTÉE.
     *
     * `families` n'a pas de school_year_id (une famille traverse les années) :
     * le rattachement à une année se fait donc sur la DATE de suppression,
     * bornée par la période de l'année courante.
     *
     * Sans cette borne, la corbeille cumulait toutes les suppressions depuis la
     * création de l'école, et une famille supprimée après la clôture d'une année
     * apparaissait à la fois dans la liste (ressuscitée par
     * VisibleUntilYearClosedScope) et dans la corbeille de cette année-là.
     */
    public function trashed(): JsonResponse
    {
        $year = SchoolYear::query()
            ->withoutGlobalScopes()
            ->whereKey(currentSchoolYearId())
            ->first();

        if (! $year) {
            return response()->json([
                'status' => 'error',
                'message' => 'Aucune année scolaire n’est configurée pour cette école.',
            ], 409);
        }

        // opened_at est nullable (années backfillées) : on retombe sur created_at
        // plutôt que d'ouvrir la borne, sinon l'historique complet reviendrait.
        $start = $year->opened_at ?? $year->created_at;

        // Pas de LIMIT : la fenêtre d'année borne déjà le volume, et une
        // troncature muette est pire que tout — l'utilisateur croirait voir
        // la liste entière. Le coût est tenu par trashedFamilyNames(), qui
        // résout tous les noms en 3 requêtes quel que soit le nombre de lignes.
        $families = Family::onlyTrashed()
            // Une famille supprimée définitivement sort de l'archive : pour
            // l'utilisateur elle n'existe plus. La ligne, elle, reste en base.
            ->whereNull('purged_at')
            ->when($start, fn ($q) => $q->where('deleted_at', '>=', $start))
            ->when($year->closed_at, fn ($q) => $q->where('deleted_at', '<=', $year->closed_at))
            ->orderByDesc('deleted_at')
            ->get();

        $names = $this->trashedFamilyNames($families);

        $items = $families->map(fn (Family $family) => [
            'id' => $family->id,
            'nom' => $names[$family->id] ?? 'Sans responsable',
            'deleted_at' => $family->deleted_at?->toIso8601String(),
        ]);

        return response()->json([
            'status' => 'success',
            'data' => ['items' => $items],
        ]);
    }

    /**
     * POST /families/{familyId}/restore
     *
     * Ne ressuscite que les lignes dont le deleted_at est exactement celui de la
     * famille : un rattachement retiré AVANT la suppression reste retiré.
     */
    public function restore(int $familyId): JsonResponse
    {
        // Résolution manuelle : le binding de route applique le scope SoftDeletes
        // et ne trouverait pas une famille supprimée. Le scope école, lui, reste
        // actif — on ne peut pas restaurer la famille d'une autre école.
        $family = Family::onlyTrashed()->find($familyId);

        if (! $family) {
            return response()->json([
                'status' => 'error',
                'message' => 'Famille introuvable dans la corbeille',
            ], 404);
        }

        if ($family->isPurged()) {
            Log::warning('FamilyDeletion: restore refused on purged family', [
                'family_id' => $family->id,
                'caller_id' => auth()->id(),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Cette famille a été supprimée définitivement et ne peut plus être restaurée.',
            ], 409);
        }

        $deletedAt = $family->deleted_at;

        if (! $deletedAt instanceof Carbon) {
            return response()->json([
                'status' => 'error',
                'message' => 'Cette famille ne peut pas être restaurée automatiquement',
            ], 409);
        }

        try {
            DB::transaction(function () use ($family, $deletedAt) {
                Family::withTrashed()
                    ->whereKey($family->id)
                    ->update(['deleted_at' => null]);

                $roleIds = UserRole::onlyTrashed()
                    ->where('roleable_type', 'family')
                    ->where('roleable_id', $family->id)
                    ->where('deleted_at', $deletedAt)
                    ->pluck('id');

                UserRole::withTrashed()
                    ->whereIn('id', $roleIds)
                    ->update(['deleted_at' => null]);

                $enrollmentIds = StudentClassroom::onlyTrashed()
                    ->withoutGlobalScope(BelongsToSchoolYearScope::class)
                    ->where('family_id', $family->id)
                    ->where('deleted_at', $deletedAt)
                    ->pluck('id');

                $enrollments = StudentClassroom::withTrashed()
                    ->withoutGlobalScope(BelongsToSchoolYearScope::class)
                    ->whereIn('id', $enrollmentIds)
                    ->get(['classroom_id', 'student_id']);

                StudentClassroom::withTrashed()
                    ->withoutGlobalScope(BelongsToSchoolYearScope::class)
                    ->whereIn('id', $enrollmentIds)
                    ->update(['deleted_at' => null]);

                if ($enrollments->isNotEmpty()) {
                    UserRole::onlyTrashed()
                        ->where('roleable_type', 'classroom')
                        ->whereIn('roleable_id', $enrollments->pluck('classroom_id')->unique())
                        ->whereIn('user_id', $enrollments->pluck('student_id')->unique())
                        ->where('deleted_at', $deletedAt)
                        ->update(['deleted_at' => null]);
                }
            });
        } catch (\Throwable $e) {
            Log::error('FamilyDeletion: restore failed', [
                'family_id' => $family->id,
                'caller_id' => auth()->id(),
                'exception' => $e->getMessage(),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Une erreur est survenue lors de la restauration de la famille',
            ], 500);
        }

        Log::info('FamilyDeletion: family restored', [
            'family_id' => $family->id,
            'caller_id' => auth()->id(),
        ]);

        Audit::log('family.restored', $family->school_id, $family);

        return response()->json([
            'status' => 'success',
            'message' => 'Famille restaurée.',
        ]);
    }

    /**
     * Inscriptions de la famille pour l'année courante (global scope actif).
     * L'historique des années clôturées n'est jamais touché par la suppression.
     */
    private function familyEnrollments(Family $family)
    {
        return StudentClassroom::query()
            ->where('family_id', $family->id)
            ->get(['id', 'classroom_id', 'student_id', 'status']);
    }

    /**
     * POST /families/{familyId}/purge — suppression définitive.
     *
     * Deuxième étage du cycle de vie : la famille était archivée, elle devient
     * irrécupérable côté application. On ne supprime RIEN en base — on pose un
     * purged_at, ce qui la sort de l'archive et bloque restore().
     *
     * L'historique n'est pas touché : VisibleUntilYearClosedScope se fonde sur
     * deleted_at, donc la famille reste visible dans les années déjà clôturées
     * au moment de son archivage, avec ses élèves et ses inscriptions.
     */
    public function purge(int $familyId): JsonResponse
    {
        // Résolution manuelle : le binding de route ne trouverait pas une
        // famille archivée. Le scope école, lui, reste actif.
        $family = Family::onlyTrashed()->find($familyId);

        if (! $family) {
            return response()->json([
                'status' => 'error',
                'message' => 'Famille introuvable dans l’archive',
            ], 404);
        }

        if ($family->isPurged()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Cette famille a déjà été supprimée définitivement.',
            ], 409);
        }

        Family::withTrashed()
            ->whereKey($family->id)
            ->update(['purged_at' => now(), 'purged_by' => auth()->id()]);

        Log::info('FamilyDeletion: family purged', [
            'family_id' => $family->id,
            'school_id' => $family->school_id,
            'caller_id' => auth()->id(),
        ]);

        Audit::log('family.purged', $family->school_id, $family);

        return response()->json([
            'status' => 'success',
            'message' => 'Famille supprimée définitivement.',
        ]);
    }

    /**
     * Ce qui interdit la suppression, sur l'ANNÉE COURANTE uniquement.
     *
     * Une famille qui a déjà réglé, ou dont un élève occupe une place en classe,
     * porte de l'activité vivante : la supprimer d'un clic libérerait une place
     * et sortirait des montants du suivi sans trace pour l'utilisateur. On exige
     * qu'il désinscrive et retire les règlements d'abord — deux gestes qui ont
     * chacun leur écran et leur confirmation.
     *
     * La portée « année courante » est acquise par les global scopes :
     * StudentClassroom et Paiement portent BelongsToSchoolYear. Une famille dont
     * toute l'activité est dans une année clôturée reste donc supprimable.
     */
    private function deletionBlockers(Family $family, int $activeEnrollments): array
    {
        $blockers = [];

        if ($activeEnrollments > 0) {
            $blockers[] = [
                'code' => 'enrollments',
                'label' => $activeEnrollments > 1
                    ? "{$activeEnrollments} inscriptions actives en classe"
                    : '1 inscription active en classe',
            ];
        }

        // whereHas applique les global scopes de Paiement : l'année est filtrée.
        $paymentLines = LignePaiement::query()
            ->whereHas('paiement', fn ($q) => $q->where('family_id', $family->id))
            ->count();

        if ($paymentLines > 0) {
            $blockers[] = [
                'code' => 'payments',
                'label' => $paymentLines > 1
                    ? "{$paymentLines} règlements enregistrés"
                    : '1 règlement enregistré',
            ];
        }

        return $blockers;
    }

    private function countMembersWithRole(Family $family, string $slug): int
    {
        return UserRole::query()
            ->where('roleable_type', 'family')
            ->where('roleable_id', $family->id)
            ->whereHas('role', fn ($q) => $q->where('slug', $slug))
            ->distinct()
            ->count('user_id');
    }

    /**
     * Combien de membres de cette famille resteront actifs dans un AUTRE
     * établissement après la suppression. Sert au message rassurant de la
     * fenêtre de confirmation.
     */
    private function countUsersActiveElsewhere(Family $family): int
    {
        $memberIds = UserRole::query()
            ->where('roleable_type', 'family')
            ->where('roleable_id', $family->id)
            ->pluck('user_id')
            ->unique();

        if ($memberIds->isEmpty()) {
            return 0;
        }

        $otherRoles = UserRole::query()
            ->whereIn('user_id', $memberIds)
            ->where(fn ($q) => $q
                ->where('roleable_type', '!=', 'family')
                ->orWhere('roleable_id', '!=', $family->id))
            ->get(['user_id', 'roleable_type', 'roleable_id']);

        if ($otherRoles->isEmpty()) {
            return 0;
        }

        $familySchools = Family::withoutGlobalScopes()
            ->whereIn('id', $otherRoles->where('roleable_type', 'family')->pluck('roleable_id')->unique())
            ->pluck('school_id', 'id');

        $classroomSchools = Classroom::withoutGlobalScopes()
            ->whereIn('id', $otherRoles->where('roleable_type', 'classroom')->pluck('roleable_id')->unique())
            ->pluck('school_id', 'id');

        return $otherRoles
            ->filter(function ($role) use ($family, $familySchools, $classroomSchools) {
                $schoolId = match ($role->roleable_type) {
                    'school' => (int) $role->roleable_id,
                    'family' => $familySchools[$role->roleable_id] ?? null,
                    'classroom' => $classroomSchools[$role->roleable_id] ?? null,
                    default => null,
                };

                return $schoolId !== null && $schoolId !== (int) $family->school_id;
            })
            ->pluck('user_id')
            ->unique()
            ->count();
    }

    /**
     * Noms d'affichage de familles SUPPRIMÉES, en lot.
     *
     * Version batch de familyName() : 3 requêtes au total (rattachements, rôles,
     * utilisateurs) au lieu de 2 par famille. C'est ce qui rend tenable l'absence
     * de LIMIT sur la corbeille.
     *
     * @param  \Illuminate\Support\Collection<int, Family>  $families
     * @return array<int, string>  family_id => nom
     */
    private function trashedFamilyNames($families): array
    {
        if ($families->isEmpty()) {
            return [];
        }

        // Chaque famille n'accepte que les rattachements coupés par SA propre
        // suppression : un responsable retiré avant ne doit pas resurgir dans
        // le nom. On compare donc deleted_at famille par famille.
        $deletedAt = $families->mapWithKeys(fn (Family $family) => [
            $family->id => $family->deleted_at?->format('Y-m-d H:i:s'),
        ]);

        return UserRole::onlyTrashed()
            ->where('roleable_type', 'family')
            ->whereIn('roleable_id', $families->pluck('id'))
            ->whereHas('role', fn ($q) => $q->where('slug', 'responsible'))
            ->with('user:id,first_name,last_name')
            ->get()
            ->filter(fn ($role) => $role->deleted_at?->format('Y-m-d H:i:s')
                === ($deletedAt[$role->roleable_id] ?? null))
            ->groupBy('roleable_id')
            ->map(function ($roles) {
                $names = $roles
                    ->pluck('user')
                    ->filter()
                    ->unique('id')
                    ->map(fn ($user) => trim(mb_strtoupper((string) $user->last_name).' '.$user->first_name))
                    ->filter()
                    ->values();

                return $names->isNotEmpty() ? $names->implode(', ') : 'Sans responsable';
            })
            ->all();
    }

    /**
     * Nom d'affichage = responsables de la famille.
     *
     * On ne passe pas par $family->responsibles() : cette relation écarte les
     * rattachements supprimés, donc une famille dans la corbeille n'aurait plus
     * aucun responsable. Pour une famille supprimée on relit les rattachements
     * coupés par CETTE suppression (même deleted_at).
     */
    private function familyName(Family $family): string
    {
        $query = UserRole::query()
            ->where('roleable_type', 'family')
            ->where('roleable_id', $family->id)
            ->whereHas('role', fn ($q) => $q->where('slug', 'responsible'));

        if ($family->trashed()) {
            $query->onlyTrashed()->where('deleted_at', $family->deleted_at);
        }

        $names = $query
            ->with('user:id,first_name,last_name')
            ->get()
            ->pluck('user')
            ->filter()
            ->unique('id')
            ->map(fn ($user) => trim(mb_strtoupper((string) $user->last_name).' '.$user->first_name))
            ->filter()
            ->values();

        return $names->isNotEmpty() ? $names->implode(', ') : 'Sans responsable';
    }
}
