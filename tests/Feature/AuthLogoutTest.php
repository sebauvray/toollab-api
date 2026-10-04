<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function logoutBearer(string $token)
{
    app('auth')->forgetGuards();

    return test()->withHeaders(['Authorization' => 'Bearer '.$token]);
}

it('ne déconnecte que l\'appareil courant', function () {
    $user = User::factory()->create(['access' => true]);
    $laptop = $user->createToken('ordinateur')->plainTextToken;
    $phone = $user->createToken('téléphone')->plainTextToken;

    logoutBearer($laptop)->postJson('/api/logout')->assertOk();

    logoutBearer($laptop)->getJson('/api/schools')->assertUnauthorized();
    logoutBearer($phone)->getJson('/api/schools')->assertOk();
    expect($user->tokens()->count())->toBe(1);
});
