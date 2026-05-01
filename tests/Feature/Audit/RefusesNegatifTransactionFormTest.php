<?php

declare(strict_types=1);

/**
 * Audit signe négatif — Step 5 : TransactionForm refuse un montant négatif.
 *
 * Vérifie que le composant TransactionForm rejette une ligne dont le montant
 * est négatif, avec le message standardisé défini dans RefusesMontantNegatif.
 *
 * @see docs/audit/2026-04-30-signe-negatif.md §2.4
 */

use App\Enums\StatutExercice;
use App\Livewire\Concerns\MontantValidation;
use App\Livewire\TransactionForm;
use App\Models\Association;
use App\Models\Categorie;
use App\Models\CompteBancaire;
use App\Models\Exercice;
use App\Models\SousCategorie;
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

    $this->categorie = Categorie::factory()->create([
        'association_id' => $this->association->id,
        'type' => 'recette',
    ]);
    $this->sc = SousCategorie::factory()->create([
        'categorie_id' => $this->categorie->id,
        'association_id' => $this->association->id,
    ]);
    $this->compte = CompteBancaire::factory()->create([
        'association_id' => $this->association->id,
        'solde_initial' => 0.0,
    ]);

    Exercice::create([
        'association_id' => $this->association->id,
        'annee' => 2025,
        'statut' => StatutExercice::Ouvert,
    ]);
    session(['exercice_actif' => 2025]);
});

afterEach(function (): void {
    TenantContext::clear();
});

it('transaction_form_refuse_montant_negatif_sur_ligne', function (): void {
    $component = Livewire::test(TransactionForm::class);

    // Ouvrir le formulaire en mode création recette
    $component->call('showNewForm', 'recette');

    // Remplir les champs obligatoires avec un montant négatif sur la ligne
    $component->set('date', '2025-10-15')
        ->set('mode_paiement', 'virement')
        ->set('compte_id', $this->compte->id)
        ->set('lignes.0.sous_categorie_id', (string) $this->sc->id)
        ->set('lignes.0.montant', '-50')
        ->call('save');

    // Le composant doit avoir une erreur sur le montant de la ligne
    $component->assertHasErrors(['lignes.0.montant']);

    // Le message doit être exactement le message standardisé
    expect($component->errors()->first('lignes.0.montant'))
        ->toBe(MontantValidation::MESSAGE);
});

it('transaction_form_accepte_montant_positif_sur_ligne', function (): void {
    $component = Livewire::test(TransactionForm::class);

    $component->call('showNewForm', 'recette');

    $component->set('date', '2025-10-15')
        ->set('mode_paiement', 'virement')
        ->set('compte_id', $this->compte->id)
        ->set('lignes.0.sous_categorie_id', (string) $this->sc->id)
        ->set('lignes.0.montant', '50')
        ->call('save');

    // Pas d'erreur sur le montant
    $component->assertHasNoErrors(['lignes.0.montant']);
});
