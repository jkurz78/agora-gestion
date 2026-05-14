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

it('tiers membre seul (pour_depenses=false) ne voit pas les raccourcis frais', function () {
    loginTiers(['pour_depenses' => false, 'pour_recettes' => false]);

    $this->get('/portail/')
        ->assertStatus(200)
        ->assertSeeText('Mon profil')
        ->assertDontSeeText('Notes de frais')
        ->assertDontSeeText('Factures partenaires')
        ->assertDontSeeText('Historique dépenses');
});

it('tiers pour_depenses voit les raccourcis Notes de frais, Factures partenaires, Historique dépenses', function () {
    loginTiers(['pour_depenses' => true, 'pour_recettes' => false]);

    $this->get('/portail/')
        ->assertStatus(200)
        ->assertSeeText('Mon profil')
        ->assertSeeText('Notes de frais')
        ->assertSeeText('Factures partenaires')
        ->assertSeeText('Historique dépenses');
});

it('tiers pour_recettes seul (sans pour_depenses) ne voit pas les raccourcis frais', function () {
    loginTiers(['pour_depenses' => false, 'pour_recettes' => true]);

    $this->get('/portail/')
        ->assertStatus(200)
        ->assertSeeText('Mon profil')
        ->assertDontSeeText('Notes de frais')
        ->assertDontSeeText('Factures partenaires')
        ->assertDontSeeText('Historique dépenses');
});

it('tableau de bord affiche les descriptions des raccourcis', function () {
    loginTiers(['pour_depenses' => true, 'pour_recettes' => false]);

    $this->get('/portail/')
        ->assertStatus(200)
        ->assertSeeText('Coordonnées et préférences')
        ->assertSeeText('Saisir et suivre vos remboursements')
        ->assertSeeText('Déposer vos factures fournisseurs')
        ->assertSeeText('Vos remboursements passés');
});
