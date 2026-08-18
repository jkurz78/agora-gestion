<?php

declare(strict_types=1);

use App\DTOs\Compta\PosteTiersReglementData;
use App\Enums\JournalComptable;
use App\Enums\ModePaiement;
use App\Enums\StatutReglement;
use App\Livewire\TransactionForm;
use App\Models\Association;
use App\Models\Compte;
use App\Models\CompteBancaire;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Compta\Migrations\SystemeSeeder;
use App\Services\Compta\PostesTiersOuvertsService;
use App\Services\Compta\PosteTiersReglementService;
use App\Services\ExerciceService;
use App\Services\TransactionService;
use App\Tenant\TenantContext;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->association = Association::factory()->create(['exercice_mois_debut' => 9]);
    $this->user = User::factory()->create();
    $this->user->associations()->attach($this->association->id, ['role' => 'admin', 'joined_at' => now()]);
    TenantContext::boot($this->association);
    session(['current_association_id' => $this->association->id]);
    $this->actingAs($this->user);

    SystemeSeeder::seed();
    $this->compteBancaire = CompteBancaire::factory()->create(['association_id' => $this->association->id]);
    Compte::factory()->create([
        'association_id' => $this->association->id,
        'numero_pcg' => '512_'.$this->compteBancaire->id,
        'classe' => 5,
        'compte_bancaire_id' => $this->compteBancaire->id,
    ]);
    $this->compteRecette = Compte::factory()->numero('706')->create();
    $this->tiers = Tiers::factory()->create(['association_id' => $this->association->id]);
});

afterEach(function (): void {
    TenantContext::clear();
});

function dateT1TransactionFormReglement(): string
{
    $range = app(ExerciceService::class)->dateRange(app(ExerciceService::class)->current());

    return $range['start']->addDays(10)->toDateString();
}

function dateT2TransactionFormReglement(): string
{
    $range = app(ExerciceService::class)->dateRange(app(ExerciceService::class)->current());

    return $range['start']->addDays(23)->toDateString();
}

it('crée une T1 et son règlement T2 à la date de règlement saisie', function (): void {
    Livewire::test(TransactionForm::class)
        ->set('type', 'recette')
        ->set('date', dateT1TransactionFormReglement())
        ->set('dateReglement', dateT2TransactionFormReglement())
        ->set('libelle', 'Cotisation datée')
        ->set('paiementRecu', true)
        ->set('mode_paiement', 'virement')
        ->set('compte_id', $this->compteBancaire->id)
        ->set('tiers_id', $this->tiers->id)
        ->set('lignes', [[
            'compte_id' => (string) $this->compteRecette->id,
            'operation_id' => '',
            'seance' => '',
            'montant' => '50.00',
            'notes' => '',
            'piece_jointe_upload' => null,
            'piece_jointe_remove' => false,
        ]])
        ->call('save')
        ->assertHasNoErrors();

    $t1 = Transaction::where('journal', JournalComptable::Vente->value)->sole();
    $t2 = Transaction::where('journal', JournalComptable::Banque->value)->sole();

    expect($t1->date->toDateString())->toBe(dateT1TransactionFormReglement())
        ->and($t2->date->toDateString())->toBe(dateT2TransactionFormReglement())
        ->and($t1->mode_paiement)->toBeNull()
        ->and($t1->statut_reglement)->toBe(StatutReglement::Recu);
});

it('remonte proprement le refus quand la date de règlement sort de l\'exercice de traitement', function (): void {
    // Le retrait des bornes d'exercice affiché sur dateReglement (côté
    // Livewire) ne supprime PAS cette règle : PosteTiersReglementService
    // impose toujours que la date du règlement (T2) reste dans l'exercice de
    // traitement — même contrainte que côté « Régler le reliquat »
    // (PosteTiersReglementModal, cf. PosteTiersReglementModalTest). Ce qui
    // change, c'est le mécanisme : ce n'était plus une règle Livewire
    // bloquant AVANT l'appel au service, mais une InvalidArgumentException
    // remontée par le service et attrapée par TransactionForm::save().
    $range = app(ExerciceService::class)->dateRange(app(ExerciceService::class)->current());
    $label = app(ExerciceService::class)->label(app(ExerciceService::class)->current());

    $composant = Livewire::test(TransactionForm::class)
        ->set('type', 'recette')
        ->set('date', dateT1TransactionFormReglement())
        ->set('dateReglement', $range['start']->subDay()->toDateString())
        ->set('libelle', 'Règlement daté hors exercice')
        ->set('paiementRecu', true)
        ->set('mode_paiement', 'virement')
        ->set('compte_id', $this->compteBancaire->id)
        ->set('tiers_id', $this->tiers->id)
        ->set('lignes', [[
            'compte_id' => (string) $this->compteRecette->id,
            'operation_id' => '',
            'seance' => '',
            'montant' => '50.00',
            'notes' => '',
            'piece_jointe_upload' => null,
            'piece_jointe_remove' => false,
        ]])
        ->call('save')
        ->assertHasErrors(['dateReglement']);

    expect($composant->errors()->first('dateReglement'))
        ->toBe("La date du règlement doit appartenir à l'exercice {$label}.");

    $composant->set('dateReglement', $range['end']->addDay()->toDateString())
        ->call('save')
        ->assertHasErrors(['dateReglement']);

    expect($composant->errors()->first('dateReglement'))
        ->toBe("La date du règlement doit appartenir à l'exercice {$label}.");
});

it('nomme le champ « date de règlement » en français quand il est manquant', function (): void {
    $composant = Livewire::test(TransactionForm::class)
        ->set('type', 'recette')
        ->set('date', dateT1TransactionFormReglement())
        ->set('dateReglement', '')
        ->set('libelle', 'Règlement sans date')
        ->set('paiementRecu', true)
        ->set('mode_paiement', 'virement')
        ->set('compte_id', $this->compteBancaire->id)
        ->set('tiers_id', $this->tiers->id)
        ->set('lignes', [[
            'compte_id' => (string) $this->compteRecette->id,
            'operation_id' => '',
            'seance' => '',
            'montant' => '50.00',
            'notes' => '',
            'piece_jointe_upload' => null,
            'piece_jointe_remove' => false,
        ]])
        ->call('save')
        ->assertHasErrors(['dateReglement' => 'required']);

    expect($composant->errors()->first('dateReglement'))
        ->toContain('date de règlement')
        ->not->toContain('dateReglement');
});

it('règle une transaction ouverte lors de son édition', function (): void {
    $ouverte = Transaction::factory()->asRecette()->create([
        'association_id' => $this->association->id,
        'date' => dateT1TransactionFormReglement(),
        'libelle' => 'Créance à régler',
        'mode_paiement' => null,
        'compte_id' => $this->compteBancaire->id,
        'tiers_id' => $this->tiers->id,
        'statut_reglement' => StatutReglement::EnAttente,
    ]);
    $ouverte->lignes()->forceDelete();
    $ouverte->lignes()->create([
        'compte_id' => $this->compteRecette->id,
        'montant' => '50.00',
        'debit' => 0,
        'credit' => 50,
    ]);
    app(TransactionService::class)->update($ouverte, [
        'type' => 'recette', 'date' => dateT1TransactionFormReglement(), 'libelle' => 'Créance à régler',
        'montant_total' => 50, 'mode_paiement' => null, 'tiers_id' => $this->tiers->id,
        'reference' => null, 'compte_id' => $this->compteBancaire->id, 'notes' => null,
    ], [[
        'id' => $ouverte->lignes()->sole()->id, 'compte_id' => $this->compteRecette->id,
        'operation_id' => null, 'seance' => null, 'montant' => '50.00', 'notes' => null,
    ]]);

    Livewire::test(TransactionForm::class)
        ->call('edit', $ouverte->id)
        ->set('paiementRecu', true)
        ->set('dateReglement', dateT2TransactionFormReglement())
        ->set('mode_paiement', 'virement')
        ->call('save')
        ->assertHasNoErrors();

    expect(Transaction::query()->where('journal', JournalComptable::Banque->value)->sole()->date->toDateString())
        ->toBe(dateT2TransactionFormReglement());
});

it('autorise la ventilation analytique après un règlement tiers sans altérer l\'écriture', function (): void {
    Livewire::test(TransactionForm::class)
        ->set('type', 'recette')
        ->set('date', dateT1TransactionFormReglement())
        ->set('dateReglement', dateT2TransactionFormReglement())
        ->set('libelle', 'Créance ventilée réglée')
        ->set('paiementRecu', true)
        ->set('mode_paiement', 'virement')
        ->set('compte_id', $this->compteBancaire->id)
        ->set('tiers_id', $this->tiers->id)
        ->set('lignes', [[
            'compte_id' => (string) $this->compteRecette->id,
            'operation_id' => '',
            'seance' => '',
            'montant' => '50.00',
            'notes' => '',
            'piece_jointe_upload' => null,
            'piece_jointe_remove' => false,
        ]])
        ->call('save')
        ->assertHasNoErrors();

    $transaction = Transaction::where('libelle', 'Créance ventilée réglée')->sole();
    $ligne = $transaction->lignes()->ventilation()->sole();

    // Photo de l'écriture avant ventilation : c'est elle qui doit rester intacte.
    $ligne411 = $transaction->lignes()->whereNotNull('lettrage_code')->sole();
    $lettrageAvant = $ligne411->lettrage_code;
    $compteAvant = (int) $ligne->compte_id;
    $montantAvant = (string) $ligne->montant;

    expect($transaction->aUnReglementTiers())->toBeTrue()
        ->and($lettrageAvant)->not->toBeNull();

    // Cas réel : la transaction est aussi pointée (rapprochement bancaire), état
    // dans lequel les lignes ne sont plus éditables directement et où « Ventiler »
    // est justement le seul geste analytique restant. Le règlement le masquait.
    $transaction->update(['statut_reglement' => StatutReglement::Pointe->value]);
    expect($transaction->fresh()->isLockedByRapprochement())->toBeTrue();

    Livewire::test(TransactionForm::class)
        ->call('edit', $transaction->id)
        ->assertSee('Ventiler');

    // La ventilation analytique découpe le montant par opération/séance SOUS le
    // compte : elle ne peut toucher ni le compte, ni le débit/crédit, ni le lettrage.
    app(TransactionService::class)->affecterLigne($ligne->fresh(), [
        ['operation_id' => null, 'seance' => 1, 'montant' => '30.00', 'notes' => 'Séance 1'],
        ['operation_id' => null, 'seance' => 2, 'montant' => '20.00', 'notes' => 'Séance 2'],
    ]);

    expect($ligne->fresh()->affectations)->toHaveCount(2)
        ->and((int) $ligne->fresh()->compte_id)->toBe($compteAvant)
        ->and((string) $ligne->fresh()->montant)->toBe($montantAvant)
        ->and($ligne411->fresh()->lettrage_code)->toBe($lettrageAvant);

    $fresh = $transaction->fresh();
    expect((bool) $fresh->equilibree)->toBeTrue()
        ->and(round((float) $fresh->lignes()->sum('debit'), 2))
        ->toBe(round((float) $fresh->lignes()->sum('credit'), 2));

    // Le même chemin passe par le composant, et la suppression aussi.
    Livewire::test(TransactionForm::class)
        ->call('edit', $transaction->id)
        ->call('ouvrirVentilation', $ligne->id)
        ->set('affectations', [[
            'operation_id' => '',
            'seance' => '',
            'montant' => '50.00',
            'notes' => 'Regroupé',
        ]])
        ->call('saveVentilation')
        ->assertHasNoErrors();

    expect($ligne->fresh()->affectations)->toHaveCount(1);

    app(TransactionService::class)->supprimerAffectations($ligne->fresh());
    expect($ligne->fresh()->affectations)->toHaveCount(0)
        ->and($ligne411->fresh()->lettrage_code)->toBe($lettrageAvant)
        ->and((bool) $transaction->fresh()->equilibree)->toBeTrue();
});

it('affiche le reliquat, l’historique et préserve le lettrage lors d’un changement de libellé', function (): void {
    Livewire::test(TransactionForm::class)
        ->set('type', 'recette')
        ->set('date', dateT1TransactionFormReglement())
        ->set('libelle', 'Créance partielle')
        ->set('paiementRecu', false)
        ->set('compte_id', $this->compteBancaire->id)
        ->set('tiers_id', $this->tiers->id)
        ->set('lignes', [[
            'compte_id' => (string) $this->compteRecette->id, 'operation_id' => '', 'seance' => '',
            'montant' => '50.00', 'notes' => '', 'piece_jointe_upload' => null, 'piece_jointe_remove' => false,
        ]])
        ->call('save')
        ->assertHasNoErrors();

    $t1 = Transaction::query()->where('journal', JournalComptable::Vente->value)->sole();
    $poste = app(PostesTiersOuvertsService::class)->pourTransaction($t1, app(ExerciceService::class)->current());
    app(PosteTiersReglementService::class)->regler(new PosteTiersReglementData(
        ligneId: $poste->ligneActionId,
        montantCentimes: 2000,
        date: new CarbonImmutable(dateT2TransactionFormReglement()),
        mode: ModePaiement::Virement,
        compteBancaireId: $this->compteBancaire->id,
        exercice: app(ExerciceService::class)->current(),
    ));

    $component = Livewire::test(TransactionForm::class)
        ->call('edit', $t1->id)
        ->assertSet('etatPaiement', 'partiel')
        ->assertSet('soldeRestantCentimes', 3000)
        ->assertSee('Partiellement réglé')
        ->assertSee('Régler le reliquat');

    expect($component->html())->toContain('poste-tiers-reglement-modal')
        ->and($component->html())->toContain('poste-tiers-reglement-annulation-modal');

    $t2 = Transaction::query()->where('journal', JournalComptable::Banque->value)->sole();
    $component->call('annulerReglement', $t2->id)
        ->assertDispatched('poste-tiers-reglement:annuler', transactionReglementId: $t2->id);

    $component->set('libelle', 'Créance partielle corrigée')->call('save')->assertHasNoErrors();
    expect($t1->fresh()->libelle)->toBe('Créance partielle corrigée')
        ->and($t1->fresh()->lignes()->whereNotNull('lettrage_code')->exists())->toBeTrue();

    $poste = app(PostesTiersOuvertsService::class)->pourTransaction($t1->fresh(), app(ExerciceService::class)->current());
    app(PosteTiersReglementService::class)->regler(new PosteTiersReglementData(
        ligneId: $poste->ligneActionId,
        montantCentimes: $poste->soldeCentimes,
        date: new CarbonImmutable(dateT2TransactionFormReglement())->addDay(),
        mode: ModePaiement::Virement,
        compteBancaireId: $this->compteBancaire->id,
        exercice: app(ExerciceService::class)->current(),
    ));

    Livewire::test(TransactionForm::class)
        ->call('edit', $t1->id)
        ->assertSet('etatPaiement', 'solde')
        ->assertSet('soldeRestantCentimes', 0)
        ->assertSee('Reçu');
});
