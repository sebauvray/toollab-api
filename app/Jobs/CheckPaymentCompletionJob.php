<?php

namespace App\Jobs;

use App\Models\Family;
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
        $details = $paiementService->getDetailsPaiement($this->family);

        if ($details['reste_a_payer'] == 0 && $details['montant_total'] > 0) {
            if ($this->previousResteAPayer === null || $this->previousResteAPayer > 0) {
                $responsables = $this->family->responsibles()->get();

                foreach ($responsables as $responsable) {
                    $responsable->notify(new PaymentCompletedNotification($this->family, $details));
                }
            }
        }
    }
}
