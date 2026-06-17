<?php

namespace App\Jobs;

use App\Models\Family;
use App\Models\Role;
use App\Models\User;
use App\Notifications\PaymentCompletedNotification;
use App\Services\PaiementService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class CheckPaymentCompletionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $family;
    protected $previousResteAPayer;

    public function __construct(Family $family, $previousResteAPayer = null)
    {
        $this->family = $family;
        $this->previousResteAPayer = $previousResteAPayer;
    }

    public function handle(PaiementService $paiementService)
    {
        // Hors HTTP, les scopes globaux BelongsToSchool / BelongsToSchoolYear
        // n'ont pas de contexte → ils fail-closed et retournent 0 row.
        // On rétablit le contexte depuis les attributs de la famille.
        $previous = [
            request()->attributes->get('current_school_id'),
            request()->attributes->get('current_school_year_id'),
        ];
        request()->attributes->set('current_school_id', $this->family->school_id);
        $activeYear = \App\Models\SchoolYear::query()
            ->withoutGlobalScopes()
            ->where('school_id', $this->family->school_id)
            ->where('is_active', true)
            ->first();
        if ($activeYear) {
            request()->attributes->set('current_school_year_id', $activeYear->id);
        }

        try {
            $details = $paiementService->getDetailsPaiement($this->family);

            if ($details['reste_a_payer'] == 0 && $details['montant_total'] > 0) {
                if ($this->previousResteAPayer === null || $this->previousResteAPayer > 0) {
                    $responsables = $this->familyResponsibles();

                    foreach ($responsables as $responsable) {
                        $responsable->notify(new PaymentCompletedNotification($this->family, $details, $activeYear?->id));
                    }
                }
            }
        } finally {
            request()->attributes->set('current_school_id', $previous[0]);
            request()->attributes->set('current_school_year_id', $previous[1]);
        }
    }

    private function familyResponsibles()
    {
        $responsibleRoleId = Role::query()->where('slug', 'responsible')->value('id');

        return User::query()
            ->whereHas('roles', function ($query) use ($responsibleRoleId) {
                $query->where('roleable_type', 'family')
                    ->where('roleable_id', $this->family->id)
                    ->when($responsibleRoleId, fn ($q) => $q->where('role_id', $responsibleRoleId));
            })
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get()
            ->unique(fn (User $user) => strtolower((string) $user->email))
            ->values();
    }
}
