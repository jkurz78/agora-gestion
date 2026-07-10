<?php

declare(strict_types=1);

use App\Enums\Espace;
use App\Livewire\AnalysePivot;
use App\Models\Association;
use App\Models\Compte;
use App\Models\CompteBancaire;
use App\Models\Operation;
use App\Models\Participant;
use App\Models\Reglement;
use App\Models\Seance;
use App\Models\SousCategorie;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Models\TypeOperation;
use App\Models\User;
use App\Tenant\TenantContext;
use Livewire\Livewire;

beforeEach(function () {
    $this->association = Association::factory()->create();
    $this->user = User::factory()->create();
    $this->user->associations()->attach($this->association->id, ['role' => 'admin', 'joined_at' => now()]);
    TenantContext::boot($this->association);
    session(['current_association_id' => $this->association->id]);
    $this->actingAs($this->user);
    view()->share('espace', Espace::Gestion);
});

afterEach(function () {
    TenantContext::clear();
});

it('renders in participants mode without toggle buttons', function () {
    Livewire::test(AnalysePivot::class, ['mode' => 'participants'])
        ->assertOk()
        ->assertSee('Analyse')
        ->assertDontSee('Financière');
});

it('renders in financier mode without toggle buttons', function () {
    Livewire::test(AnalysePivot::class, ['mode' => 'financier'])
        ->assertOk()
        ->assertSee('Analyse')
        ->assertDontSee('Participants / Règlements');
});

it('defaults to participants mode', function () {
    $component = Livewire::test(AnalysePivot::class, ['mode' => 'participants']);
    expect($component->get('mode'))->toBe('participants');
});

it('returns participants data with correct fields', function () {
    $typeOp = TypeOperation::factory()->create(['association_id' => $this->association->id]);
    $operation = Operation::factory()->create([
        'association_id' => $this->association->id,
        'type_operation_id' => $typeOp->id,
    ]);
    $tiers = Tiers::factory()->create([
        'association_id' => $this->association->id,
        'nom' => 'Dupont',
        'prenom' => 'Marie',
        'ville' => 'Paris',
    ]);
    $participant = Participant::create([
        'association_id' => $this->association->id,
        'tiers_id' => $tiers->id,
        'operation_id' => $operation->id,
        'date_inscription' => '2026-01-15',
    ]);
    $seance = Seance::create([
        'operation_id' => $operation->id,
        'numero' => 1,
        'date' => '2026-01-20',
        'titre' => 'Séance test',
    ]);
    Reglement::create([
        'participant_id' => $participant->id,
        'seance_id' => $seance->id,
        'mode_paiement' => 'cb',
        'montant_prevu' => 25.00,
    ]);

    $component = Livewire::test(AnalysePivot::class, ['mode' => 'participants']);
    $data = $component->get('participantsData');

    expect($data)->toBeArray()->not->toBeEmpty();
    expect($data[0])->toHaveKeys([
        'Opération', 'Type opération', 'Séance', 'Nom', 'Prénom',
        'Ville', 'Mode paiement', 'Montant prévu',
    ]);
    expect($data[0]['Nom'])->toBe('Dupont');
    expect($data[0]['Montant prévu'])->toBe(25.0);
});

it('returns financier data with correct fields including temporal dimensions', function () {
    $compte = CompteBancaire::factory()->create(['association_id' => $this->association->id]);
    $tiers = Tiers::factory()->create(['association_id' => $this->association->id, 'nom' => 'Fournisseur', 'pour_depenses' => true]);
    // code_cerfa déclenche la matérialisation Compte/Famille — la ventilation est
    // portée par transaction_lignes.compte_id (source du mode financier de l'Analyse pivot).
    $sousCategorie = SousCategorie::factory()->create(['association_id' => $this->association->id, 'code_cerfa' => '606']);
    $compteVentilation = Compte::where('numero_pcg', '606')
        ->where('association_id', $this->association->id)
        ->firstOrFail();
    $transaction = Transaction::create([
        'association_id' => $this->association->id,
        'tiers_id' => $tiers->id,
        'compte_id' => $compte->id,
        'type' => 'depense',
        'date' => '2026-01-15',
        'libelle' => 'Test',
        'montant_total' => 100.00,
        'mode_paiement' => 'virement',
        'saisi_par' => $this->user->id,
    ]);
    TransactionLigne::create([
        'transaction_id' => $transaction->id,
        'sous_categorie_id' => $sousCategorie->id,
        'montant' => 100.00,
        'compte_id' => $compteVentilation->id,
        'debit' => 100.00,
        'credit' => 0.0,
    ]);

    $component = Livewire::test(AnalysePivot::class, ['mode' => 'financier']);
    $data = $component->get('financierData');

    expect($data)->toBeArray()->not->toBeEmpty();
    expect($data[0])->toHaveKeys([
        'Tiers', 'Date', 'Montant', 'Sous-catégorie', 'Catégorie', 'Type', 'Compte',
        'Mois', 'Trimestre', 'Semestre',
    ]);
    expect($data[0]['Montant'])->toBe(-100.0); // dépense → signe négatif (spine)
    // January 2026 → exercice 2025 → T2 (Dec-Feb), S1 (Sept-Feb)
    expect($data[0]['Mois'])->toBe('Janvier 2026');
    expect($data[0]['Trimestre'])->toBe('T2 2025-2026');
    expect($data[0]['Semestre'])->toBe('S1 2025-2026');
});

it('injecte le centrage CSS du popup de filtre PivotTable (Pb 2)', function () {
    Livewire::test(AnalysePivot::class, ['mode' => 'financier'])
        ->assertSeeHtml('.pvtFilterBox');
});
