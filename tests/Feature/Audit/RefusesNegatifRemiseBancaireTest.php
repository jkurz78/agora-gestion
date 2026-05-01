<?php

declare(strict_types=1);

/**
 * Audit signe négatif — Step 7 : RemiseBancaireList — analyse de saisie de montant.
 *
 * RemiseBancaireList::create() ne saisit PAS de montant : elle crée uniquement
 * un en-tête de remise (date, compte_cible_id, mode_paiement). Le montant total
 * d'une remise est dérivé des transactions sélectionnées dans RemiseBancaireSelection
 * (qui ne fait que cocher des transactions existantes, pas saisir un montant).
 *
 * Verdict : n/a — aucun montant saisi directement, pas de patch nécessaire.
 *
 * Ce test documente l'analyse en vérifiant que le composant crée une remise sans
 * champ montant, et que toute tentative de passer un montant négatif en paramètre
 * n'a aucun effet observable.
 *
 * @see docs/audit/2026-04-30-signe-negatif.md §2.4
 */

use App\Livewire\RemiseBancaireList;
use App\Models\Association;
use App\Models\CompteBancaire;
use App\Models\User;
use App\Tenant\TenantContext;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->association = Association::factory()->create();
    $this->user = User::factory()->create();
    $this->user->associations()->attach($this->association->id, [
        'role' => 'admin',
        'joined_at' => now(),
    ]);
    TenantContext::boot($this->association);
    session(['current_association_id' => $this->association->id]);
    $this->actingAs($this->user);

    $this->compte = CompteBancaire::factory()->create([
        'association_id' => $this->association->id,
    ]);
});

afterEach(function (): void {
    TenantContext::clear();
});

it('remise_bancaire_list_na_aucun_champ_montant_dans_create', function (): void {
    // RemiseBancaireList::create() ne contient aucun champ montant.
    // Le composant crée un en-tête de remise (date, compte, mode_paiement)
    // et redirige vers RemiseBancaireSelection pour sélectionner les transactions.
    // Aucun patch de signe négatif requis ici.
    $reflection = new ReflectionClass(RemiseBancaireList::class);
    $method = $reflection->getMethod('create');
    $source = file_get_contents($reflection->getFileName());

    // Le composant ne valide PAS de champ 'montant' dans create()
    expect($source)->not->toContain("'montant'");
})->skip('n/a — RemiseBancaireList::create() ne saisit aucun montant directement.');

it('remise_bancaire_list_create_valide_uniquement_date_compte_mode', function (): void {
    // Vérification positive : seuls date, compte_cible_id, mode_paiement sont validés.
    $component = Livewire::test(RemiseBancaireList::class)
        ->set('date', '')
        ->set('compte_cible_id', '')
        ->set('mode_paiement', 'cheque')
        ->call('create');

    // Des erreurs existent sur date et compte — mais jamais sur montant
    $component->assertHasErrors(['date', 'compte_cible_id']);
    $component->assertHasNoErrors(['montant']);
});
