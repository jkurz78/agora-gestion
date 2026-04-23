<?php

declare(strict_types=1);

use App\Models\Association;
use App\Models\Tiers;
use App\Support\MonoAssociation;
use App\Tenant\TenantContext;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    MonoAssociation::flush();
    TenantContext::clear();
    DB::table('association')->delete();
});

function loginTiers(array $flags): Tiers
{
    $asso = Association::factory()->create(['slug' => 'mono']);
    TenantContext::boot($asso);
    $tiers = Tiers::factory()->create(array_merge([
        'association_id' => $asso->id,
    ], $flags));
    Auth::guard('tiers-portail')->login($tiers);
    session(['portail.last_activity_at' => now()->timestamp]);

    return $tiers;
}

it('affiche le bloc Membre placeholder et cache Partenaire si pour_recettes only', function () {
    loginTiers(['pour_depenses' => false, 'pour_recettes' => true]);

    $this->get('/portail/')
        ->assertStatus(200)
        ->assertSeeText('Espace membre')
        ->assertDontSee('Vos notes de frais');
});

it('affiche le bloc Partenaire avec NDF et cache Membre si pour_depenses only', function () {
    loginTiers(['pour_depenses' => true, 'pour_recettes' => false]);

    $this->get('/portail/')
        ->assertStatus(200)
        ->assertSeeText('Vos notes de frais')
        ->assertDontSeeText('Espace membre');
});

it('affiche les deux blocs dans l\'ordre Membre puis Partenaire si les deux flags', function () {
    loginTiers(['pour_depenses' => true, 'pour_recettes' => true]);

    $response = $this->get('/portail/');
    $response->assertStatus(200)->assertSeeText('Espace membre')->assertSeeText('Vos notes de frais');

    $html = $response->getContent();
    $posMembre = strpos($html, 'Espace membre');
    $posNdf = strpos($html, 'Vos notes de frais');

    expect($posMembre)->toBeLessThan($posNdf);
});

it('affiche le message neutre si aucun flag', function () {
    loginTiers(['pour_depenses' => false, 'pour_recettes' => false]);

    $this->get('/portail/')
        ->assertStatus(200)
        ->assertSeeText('Aucun espace activé');
});
