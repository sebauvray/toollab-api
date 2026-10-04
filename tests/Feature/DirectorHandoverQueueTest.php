<?php

use App\Notifications\DirectorHandoverInvitation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

it('ne stocke jamais le jeton de passation en clair dans la file d\'attente', function () {
    config(['queue.default' => 'database']);
    $token = str_repeat('Z', 64);

    Notification::route('mail', 'cible@test.fr')
        ->notify(new DirectorHandoverInvitation('École', 'Directeur', $token, now()->addDays(7)));

    $payloads = DB::table('jobs')->pluck('payload')->implode('');

    expect($payloads)->not->toBe('')
        ->and($payloads)->not->toContain($token);
});
