<?php

declare(strict_types=1);

use App\Enums\ModePaiement;
use App\Enums\RoleAssociation;
use App\Enums\StatutReglement;
use App\Enums\TypeTransaction;
use App\Livewire\TiersQuickView;
use App\Models\Association;
use App\Models\SousCategorie;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Models\User;
use App\Services\RecuFiscalService;
use App\Tenant\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('local');
});

function setupAssoUser17(): array
{
    $asso = Association::factory()->create([
        'eligible_recu_fiscal' => true,
        'signataire_nom' => 'J',
        'signataire_qualite' => 'P',
    ]);
    TenantContext::boot($asso);
    $user = User::factory()->create();
    $user->associations()->attach($asso, [
        'role' => RoleAssociation::Admin->value,
        'joined_at' => now(),
    ]);

    return [$asso, $user];
}

it('affiche la liste des dons d\'un tiers avec bouton télécharger', function () {
    [$asso, $user] = setupAssoUser17();
    $ligne = $this->ligneDonValide();
    $tiersId = $ligne->transaction->tiers_id;

    Livewire::actingAs($user)->test(TiersQuickView::class)
        ->dispatch('open-tiers-quick-view', tiersId: $tiersId)
        ->assertSeeText('Télécharger reçu fiscal');
});

it('affiche le numéro du reçu déjà émis', function () {
    [$asso, $user] = setupAssoUser17();
    $ligne = $this->ligneDonValide();
    $tiersId = $ligne->transaction->tiers_id;

    $recu = app(RecuFiscalService::class)->obtenirOuGenerer($ligne, $user);

    Livewire::actingAs($user)->test(TiersQuickView::class)
        ->dispatch('open-tiers-quick-view', tiersId: $tiersId)
        ->assertSeeText($recu->numero);
});

it('annule + ré-émet un reçu via la modale', function () {
    [$asso, $user] = setupAssoUser17();
    $ligne = $this->ligneDonValide();
    $tiersId = $ligne->transaction->tiers_id;

    $ancien = app(RecuFiscalService::class)->obtenirOuGenerer($ligne, $user);

    Livewire::actingAs($user)->test(TiersQuickView::class)
        ->dispatch('open-tiers-quick-view', tiersId: $tiersId)
        ->call('ouvrirModaleAnnulation', $ancien->id)
        ->set('motifAnnulation', 'Adresse corrigée')
        ->call('confirmerReEmission');

    $ancien->refresh();
    expect($ancien->isAnnule())->toBeTrue();
    expect($ancien->remplace_par_id)->not->toBeNull();
});

it('affiche un avertissement HelloAsso si le don provient d\'HelloAsso', function () {
    [$asso, $user] = setupAssoUser17();
    $ligne = $this->ligneDonValide();
    $ligne->transaction->update(['helloasso_payment_id' => 99999]);
    $tiersId = $ligne->transaction->tiers_id;

    Livewire::actingAs($user)->test(TiersQuickView::class)
        ->dispatch('open-tiers-quick-view', tiersId: $tiersId)
        ->assertSeeText('HelloAsso');
});

it('affiche un avertissement si l\'asso a été modifiée depuis l\'enregistrement de la transaction', function () {
    [$asso, $user] = setupAssoUser17();
    // Transaction créée il y a 1 an dans le système (bypass fillable via DB)
    $ligne = $this->ligneDonValide();
    DB::table('transactions')
        ->where('id', $ligne->transaction->id)
        ->update(['created_at' => now()->subYear()]);
    // L'asso est modifiée après (updated_at = maintenant, > created_at il y a 1 an)
    $asso->update(['signataire_nom' => 'Mis à jour']);
    $tiersId = $ligne->transaction->tiers_id;

    Livewire::actingAs($user)->test(TiersQuickView::class)
        ->dispatch('open-tiers-quick-view', tiersId: $tiersId)
        ->assertSeeText('coordonnées');
});

it('ne déclenche PAS l\'avertissement si tiers/asso modifiés AVANT la création de la transaction', function () {
    [$asso, $user] = setupAssoUser17();
    // Tiers créé maintenant
    $tiers = Tiers::factory()->create([
        'type' => 'particulier',
        'nom' => 'Dupont',
        'prenom' => 'Marie',
        'adresse_ligne1' => '12 rue des Lilas',
        'code_postal' => '75001',
        'ville' => 'Paris',
    ]);
    // Transaction enregistrée APRÈS (created_at = maintenant par défaut),
    // mais avec une date métier antérieure d'un mois
    $sousCatDon = SousCategorie::factory()->pourDons()->create();
    $tx = Transaction::factory()->create([
        'tiers_id' => $tiers->id,
        'date' => now()->subMonth()->toDateString(),
        'statut_reglement' => StatutReglement::Recu,
        'mode_paiement' => ModePaiement::Cheque,
        'type' => TypeTransaction::Recette,
    ]);
    TransactionLigne::factory()->create([
        'transaction_id' => $tx->id,
        'sous_categorie_id' => $sousCatDon->id,
        'montant' => 100,
    ]);

    Livewire::actingAs($user)->test(TiersQuickView::class)
        ->dispatch('open-tiers-quick-view', tiersId: $tiers->id)
        ->assertDontSeeText('coordonnées modifiées')
        ->assertDontSeeText('HelloAsso');
});

it('désactive le bouton télécharger si l\'asso n\'est pas éligible', function () {
    $asso = Association::factory()->create([
        'eligible_recu_fiscal' => false,
        'signataire_nom' => 'J',
        'signataire_qualite' => 'P',
    ]);
    TenantContext::boot($asso);
    $user = User::factory()->create();
    $user->associations()->attach($asso, ['role' => RoleAssociation::Admin->value, 'joined_at' => now()]);
    $ligne = $this->ligneDonValide();

    Livewire::actingAs($user)->test(TiersQuickView::class)
        ->dispatch('open-tiers-quick-view', tiersId: $ligne->transaction->tiers_id)
        ->assertSeeText('Reçu indisponible')
        ->assertSeeText('Configurer dans Paramètres');
});

it('désactive le bouton si la transaction n\'est pas encaissée', function () {
    [$asso, $user] = setupAssoUser17();
    $ligne = $this->ligneDonValide(transactionOverrides: [
        'statut_reglement' => StatutReglement::EnAttente,
    ]);

    Livewire::actingAs($user)->test(TiersQuickView::class)
        ->dispatch('open-tiers-quick-view', tiersId: $ligne->transaction->tiers_id)
        ->assertSee('Don non encaissé');
});
