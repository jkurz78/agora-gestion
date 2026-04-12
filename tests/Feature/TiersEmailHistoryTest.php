<?php

declare(strict_types=1);

use App\Models\EmailLog;
use App\Models\Tiers;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('displays email history on tiers transactions page', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $tiers = Tiers::factory()->create(['email' => 'test@e.com']);

    EmailLog::create([
        'tiers_id' => $tiers->id,
        'categorie' => 'communication',
        'destinataire_email' => 'test@e.com',
        'destinataire_nom' => $tiers->displayName(),
        'objet' => 'Convocation AG',
        'statut' => 'envoye',
        'envoye_par' => $user->id,
    ]);

    $response = $this->get(route('tiers.transactions', $tiers));
    $response->assertOk();
    $response->assertSee('Convocation AG');
    $response->assertSee('Emails envoyés');
});

it('shows empty state when no emails', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $tiers = Tiers::factory()->create();

    $response = $this->get(route('tiers.transactions', $tiers));
    $response->assertOk();
    $response->assertSee('Aucun email');
});
