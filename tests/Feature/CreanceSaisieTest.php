<?php

declare(strict_types=1);

/**
 * Volet A — Saisie de créance (Task A1 + A2)
 *
 * A1 : Teste que TransactionForm accepte paiementRecu = false (recette attendue)
 * et que la transaction créée a mode_paiement = null avec une ligne 411 non lettrée
 * et aucune ligne 5112.
 *
 * A2 : Teste que ReglementOperationService::marquerRecu accepte un ModePaiement
 * pour une créance (mode_paiement null) et génère la T2 d'encaissement.
 */

use App\Enums\Espace;
use App\Enums\ModePaiement;
use App\Livewire\TransactionForm;
use App\Models\Association;
use App\Models\Categorie;
use App\Models\Compte;
use App\Models\CompteBancaire;
use App\Models\SousCategorie;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Models\User;
use App\Services\Compta\Migrations\SystemeSeeder;
use App\Services\ReglementOperationService;
use App\Tenant\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->association = Association::factory()->create(['anthropic_api_key' => null]);
    TenantContext::boot($this->association);
    session(['current_association_id' => $this->association->id]);
    session(['exercice_actif' => 2025]);

    $this->user = User::factory()->create(['dernier_espace' => Espace::Compta]);
    $this->user->associations()->attach($this->association->id, ['role' => 'admin', 'joined_at' => now()]);
    $this->actingAs($this->user);

    // Comptes système : 411, 5112, etc.
    SystemeSeeder::seed();

    // Tiers recette
    $this->tiers = Tiers::factory()->create(['association_id' => $this->association->id]);

    // Catégorie + sous-catégorie recette (classe 7)
    $this->categorie = Categorie::factory()->recette()->create(['association_id' => $this->association->id]);
    $this->sousCategorie = SousCategorie::create([
        'association_id' => $this->association->id,
        'categorie_id' => $this->categorie->id,
        'nom' => 'Cotisations',
        'code_cerfa' => '706',
    ]);
    Compte::firstOrCreate(
        ['association_id' => $this->association->id, 'numero_pcg' => '706'],
        [
            'intitule' => 'Cotisations',
            'classe' => 7,
            'lettrable' => false,
            'actif' => true,
            'est_systeme' => false,
            'pour_inscriptions' => false,
            'categorie_id' => $this->categorie->id,
        ]
    );

    // Compte bancaire (pas utilisé pour une créance, mais le formulaire peut en avoir un)
    $this->compte = CompteBancaire::factory()->create(['association_id' => $this->association->id]);
});

afterEach(function () {
    TenantContext::clear();
    session()->forget('exercice_actif');
});

it('recette avec paiementRecu=false crée une transaction avec mode_paiement null (créance 411)', function () {
    Livewire::test(TransactionForm::class)
        ->call('showNewForm', 'recette')
        ->set('paiementRecu', false)
        ->set('date', '2025-10-15')
        ->set('libelle', 'Cotisation attendue')
        ->set('tiers_id', $this->tiers->id)
        ->set('lignes', [[
            'id' => null,
            'sous_categorie_id' => (string) $this->sousCategorie->id,
            'operation_id' => '',
            'seance' => '',
            'montant' => '100.00',
            'notes' => '',
            'piece_jointe_path' => null,
            'piece_jointe_upload' => null,
            'piece_jointe_remove' => false,
            'piece_jointe_existing_url' => null,
            'piece_jointe_filename' => null,
        ]])
        ->call('save')
        ->assertHasNoErrors();

    // Transaction créée avec mode_paiement null
    $tx = Transaction::where('tiers_id', $this->tiers->id)->first();
    expect($tx)->not->toBeNull();
    expect($tx->mode_paiement)->toBeNull();

    // Ligne 411 présente et non lettrée
    $ligne411 = TransactionLigne::where('transaction_id', $tx->id)
        ->whereHas('compte', fn ($q) => $q->where('numero_pcg', '411'))
        ->first();
    expect($ligne411)->not->toBeNull();
    expect($ligne411->lettrage_code)->toBeNull();

    // Aucune ligne 5112 (pas de portage trésorerie)
    $ligne5112 = TransactionLigne::where('transaction_id', $tx->id)
        ->whereHas('compte', fn ($q) => $q->where('numero_pcg', '5112'))
        ->first();
    expect($ligne5112)->toBeNull();
});

it('recette avec paiementRecu=true exige le mode_paiement (comportement actuel inchangé)', function () {
    Livewire::test(TransactionForm::class)
        ->call('showNewForm', 'recette')
        ->set('paiementRecu', true)
        ->set('date', '2025-10-15')
        ->set('libelle', 'Cotisation reçue')
        ->set('tiers_id', $this->tiers->id)
        ->set('lignes', [[
            'id' => null,
            'sous_categorie_id' => (string) $this->sousCategorie->id,
            'operation_id' => '',
            'seance' => '',
            'montant' => '100.00',
            'notes' => '',
            'piece_jointe_path' => null,
            'piece_jointe_upload' => null,
            'piece_jointe_remove' => false,
            'piece_jointe_existing_url' => null,
            'piece_jointe_filename' => null,
        ]])
        // mode_paiement vide → doit déclencher une erreur de validation
        ->call('save')
        ->assertHasErrors(['mode_paiement']);
});

// ============================================================
// A2 — Marquer reçu d'une créance en capturant le mode
// ============================================================

it('marquerRecu avec mode capture le mode_paiement et génère la T2 encaissement', function () {
    // 1. Créer une créance via le formulaire (mode_paiement = null)
    Livewire::test(TransactionForm::class)
        ->call('showNewForm', 'recette')
        ->set('paiementRecu', false)
        ->set('date', '2025-10-15')
        ->set('libelle', 'Cotisation attendue A2')
        ->set('tiers_id', $this->tiers->id)
        ->set('lignes', [[
            'id' => null,
            'sous_categorie_id' => (string) $this->sousCategorie->id,
            'operation_id' => '',
            'seance' => '',
            'montant' => '150.00',
            'notes' => '',
            'piece_jointe_path' => null,
            'piece_jointe_upload' => null,
            'piece_jointe_remove' => false,
            'piece_jointe_existing_url' => null,
            'piece_jointe_filename' => null,
        ]])
        ->call('save')
        ->assertHasNoErrors();

    $tx = Transaction::where('tiers_id', $this->tiers->id)
        ->where('libelle', 'Cotisation attendue A2')
        ->first();
    expect($tx)->not->toBeNull();
    expect($tx->mode_paiement)->toBeNull();

    // 2. Marquer reçu en fournissant le mode Cheque
    app(ReglementOperationService::class)->marquerRecu($tx, ModePaiement::Cheque);

    $tx->refresh();

    // Le mode est désormais Cheque
    expect($tx->mode_paiement)->toBe(ModePaiement::Cheque);

    // La ligne 411 est lettrée (encaissement effectué)
    $compte411 = Compte::ofNumero('411');
    expect($compte411)->not->toBeNull();
    $ligne411 = TransactionLigne::where('transaction_id', $tx->id)
        ->where('compte_id', (int) $compte411->id)
        ->first();
    expect($ligne411)->not->toBeNull();
    expect($ligne411->lettrage_code)->not->toBeNull();

    // T2 d'encaissement créée
    $service = app(ReglementOperationService::class);
    $t2 = $service->trouverEncaissementT2($tx);
    expect($t2)->not->toBeNull();
});

it('marquerRecu sans mode sur une créance reste rétro-compatible (skip T2 silencieux)', function () {
    // Comportement de rétro-compatibilité : si mode null et aucun mode fourni,
    // statut passe à Recu mais T2 n'est pas générée (skip silencieux existant).
    Livewire::test(TransactionForm::class)
        ->call('showNewForm', 'recette')
        ->set('paiementRecu', false)
        ->set('date', '2025-10-16')
        ->set('libelle', 'Créance sans mode')
        ->set('tiers_id', $this->tiers->id)
        ->set('lignes', [[
            'id' => null,
            'sous_categorie_id' => (string) $this->sousCategorie->id,
            'operation_id' => '',
            'seance' => '',
            'montant' => '50.00',
            'notes' => '',
            'piece_jointe_path' => null,
            'piece_jointe_upload' => null,
            'piece_jointe_remove' => false,
            'piece_jointe_existing_url' => null,
            'piece_jointe_filename' => null,
        ]])
        ->call('save')
        ->assertHasNoErrors();

    $tx = Transaction::where('libelle', 'Créance sans mode')->first();
    expect($tx)->not->toBeNull();
    expect($tx->mode_paiement)->toBeNull();

    // marquerRecu sans fournir de mode (rétro-compat)
    app(ReglementOperationService::class)->marquerRecu($tx);

    $tx->refresh();
    // Statut passe bien à Recu
    expect($tx->statut_reglement->value)->toBe('recu');
    // Mode reste null
    expect($tx->mode_paiement)->toBeNull();
    // Pas de T2 (skip silencieux)
    expect(app(ReglementOperationService::class)->trouverEncaissementT2($tx))->toBeNull();
});
