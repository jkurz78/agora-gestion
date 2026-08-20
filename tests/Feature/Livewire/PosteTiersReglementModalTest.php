<?php

declare(strict_types=1);

use App\DTOs\Compta\PosteTiersReglementData;
use App\Enums\ModePaiement;
use App\Enums\SensVentilation;
use App\Enums\StatutReglement;
use App\Enums\TypeTransaction;
use App\Livewire\Compta\AnnulationReglementTiersModal;
use App\Livewire\Compta\PosteTiersReglementModal;
use App\Models\Association;
use App\Models\CompteBancaire;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Services\Compta\EcritureGenerator;
use App\Services\Compta\PosteTiersReglementService;
use App\Support\MontantDecimal;
use App\Tenant\TenantContext;
use Carbon\CarbonImmutable;
use Livewire\Livewire;
use Tests\Support\CreatesPartieDoubleContext;

uses(CreatesPartieDoubleContext::class);

beforeEach(function (): void {
    $this->setupPartieDoubleContext();
    $this->ecritures = app(EcritureGenerator::class);
    $this->reglements = app(PosteTiersReglementService::class);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

/**
 * @return array{Transaction, TransactionLigne}
 */
function creerPosteOuvertPourModaleReglement(object $contexte, int $montantCentimes = 10000): array
{
    $tiers = Tiers::factory()->create(['association_id' => $contexte->association->id]);
    $transaction = $contexte->ecritures->pourRecetteACredit(
        tiers: $tiers,
        ventilations: [['sens' => SensVentilation::Credit,
            'compte' => $contexte->compte706,
            'montant' => MontantDecimal::depuisCentimes($montantCentimes),
        ]],
        dateConstatation: new DateTimeImmutable('2025-10-01'),
        libelle: 'Cotisation annuelle à encaisser',
    );
    $transaction->update(['statut_reglement' => StatutReglement::EnAttente]);

    /** @var TransactionLigne $ligne */
    $ligne = $transaction->lignes()
        ->whereHas('compte', fn ($query) => $query->where('numero_pcg', '411'))
        ->firstOrFail();

    return [$transaction->fresh(), $ligne];
}

/**
 * @return array{Transaction, TransactionLigne}
 */
function creerDetteOuvertePourModaleReglement(object $contexte, int $montantCentimes = 10000): array
{
    $tiers = Tiers::factory()->create(['association_id' => $contexte->association->id]);
    $transaction = $contexte->ecritures->pourDepenseACredit(
        tiers: $tiers,
        ventilations: [[
            'compte' => $contexte->compte606,
            'montant' => MontantDecimal::depuisCentimes($montantCentimes),
        ]],
        dateConstatation: new DateTimeImmutable('2025-10-01'),
        libelle: 'Facture fournisseur à payer',
    );
    $transaction->update(['statut_reglement' => StatutReglement::EnAttente]);

    /** @var TransactionLigne $ligne */
    $ligne = $transaction->lignes()
        ->whereHas('compte', fn ($query) => $query->where('numero_pcg', '401'))
        ->firstOrFail();

    return [$transaction->fresh(), $ligne];
}

test('ouvrir préremplit le solde et la date du jour dans les bornes de l exercice', function (): void {
    CarbonImmutable::setTestNow('2026-07-23 10:00:00');
    [, $ligne] = creerPosteOuvertPourModaleReglement($this);

    Livewire::test(PosteTiersReglementModal::class, ['exercice' => 2025])
        ->dispatch('poste-tiers-reglement:ouvrir', ligneId: (int) $ligne->id, exercice: 2025)
        ->assertSet('ligneId', (int) $ligne->id)
        ->assertSet('montant', '100,00')
        ->assertSet('dateReglement', '2026-07-23')
        ->assertSet('titre', 'Règlement tiers')
        ->assertSet('posteOrigine', fn (string $posteOrigine): bool => str_ends_with(
            $posteOrigine,
            'Cotisation annuelle à encaisser',
        ));
});

test('le compte bancaire est masqué tant que le mode ne nécessite pas de banque', function (): void {
    [, $ligne] = creerPosteOuvertPourModaleReglement($this);

    Livewire::test(PosteTiersReglementModal::class, ['exercice' => 2025])
        ->dispatch('poste-tiers-reglement:ouvrir', ligneId: (int) $ligne->id, exercice: 2025)
        ->assertDontSee('Compte bancaire')
        ->set('mode', ModePaiement::Especes->value)
        ->assertDontSee('Compte bancaire');
});

test('ouvrir borne la date par le début et la fin de l exercice', function (string $maintenant, string $dateAttendue): void {
    CarbonImmutable::setTestNow($maintenant);
    [, $ligne] = creerPosteOuvertPourModaleReglement($this);

    Livewire::test(PosteTiersReglementModal::class, ['exercice' => 2025])
        ->dispatch('poste-tiers-reglement:ouvrir', ligneId: (int) $ligne->id, exercice: 2025)
        ->assertSet('dateReglement', $dateAttendue);
})->with([
    'avant le début' => ['2025-08-31 10:00:00', '2025-09-01'],
    'après la fin' => ['2026-09-01 10:00:00', '2026-08-31'],
]);

test('enregistrer crée le règlement et notifie les consommateurs', function (): void {
    CarbonImmutable::setTestNow('2026-07-23 10:00:00');
    [, $ligne] = creerPosteOuvertPourModaleReglement($this);

    Livewire::test(PosteTiersReglementModal::class, ['exercice' => 2025])
        ->dispatch('poste-tiers-reglement:ouvrir', ligneId: (int) $ligne->id, exercice: 2025)
        ->set('montant', '30,00')
        ->set('mode', ModePaiement::Virement->value)
        ->set('compteBancaireId', (int) $this->compteBancaire->id)
        ->call('enregistrer')
        ->assertHasNoErrors()
        ->assertDispatched('poste-tiers-reglement:enregistre')
        ->assertDispatched('poste-tiers-reglement-modal-close');

    expect(Transaction::query()
        ->where('journal', 'banque')
        ->whereDate('date', '2026-07-23')
        ->where('montant_total', '30.00')
        ->exists())->toBeTrue();
});

test('le compte bancaire apparaît et se préremplit avec le compte par défaut quand il est requis', function (): void {
    [, $ligne] = creerPosteOuvertPourModaleReglement($this);
    $compteParDefaut = CompteBancaire::factory()->create([
        'association_id' => (int) $this->association->id,
        'nom' => 'Banque par défaut',
    ]);
    $this->association->update(['facture_compte_bancaire_id' => (int) $compteParDefaut->id]);

    Livewire::test(PosteTiersReglementModal::class, ['exercice' => 2025])
        ->dispatch('poste-tiers-reglement:ouvrir', ligneId: (int) $ligne->id, exercice: 2025)
        ->assertDontSee('Compte bancaire')
        ->set('mode', ModePaiement::Virement->value)
        ->assertSee('Compte bancaire')
        ->assertSet('compteBancaireId', (int) $compteParDefaut->id);
});

test('la dernière banque utilisée prime sur le compte par défaut', function (): void {
    [, $ligne] = creerPosteOuvertPourModaleReglement($this);
    $compteParDefaut = CompteBancaire::factory()->create([
        'association_id' => (int) $this->association->id,
        'nom' => 'Banque par défaut',
    ]);
    $derniereBanque = CompteBancaire::factory()->create([
        'association_id' => (int) $this->association->id,
        'nom' => 'Dernière banque utilisée',
    ]);
    $this->association->update(['facture_compte_bancaire_id' => (int) $compteParDefaut->id]);

    Transaction::factory()->create([
        'association_id' => (int) $this->association->id,
        'type' => TypeTransaction::Recette,
        'date' => '2026-07-22',
        'journal' => 'banque',
        'mode_paiement' => ModePaiement::Virement,
        'compte_id' => (int) $derniereBanque->id,
    ]);

    Livewire::test(PosteTiersReglementModal::class, ['exercice' => 2025])
        ->dispatch('poste-tiers-reglement:ouvrir', ligneId: (int) $ligne->id, exercice: 2025)
        ->set('mode', ModePaiement::Virement->value)
        ->assertSet('compteBancaireId', (int) $derniereBanque->id);
});

test('enregistrer exige un compte bancaire pour un virement', function (): void {
    [, $ligne] = creerPosteOuvertPourModaleReglement($this);

    Livewire::test(PosteTiersReglementModal::class, ['exercice' => 2025])
        ->dispatch('poste-tiers-reglement:ouvrir', ligneId: (int) $ligne->id, exercice: 2025)
        ->set('mode', ModePaiement::Virement->value)
        ->set('compteBancaireId', null)
        ->call('enregistrer')
        ->assertHasErrors(['compteBancaireId' => 'required'])
        ->assertSee('Ce mode de règlement nécessite un compte bancaire.')
        ->assertNotDispatched('poste-tiers-reglement:enregistre');
});

test('enregistrer exige un compte bancaire pour un chèque fournisseur', function (): void {
    [, $ligne] = creerDetteOuvertePourModaleReglement($this);

    Livewire::test(PosteTiersReglementModal::class, ['exercice' => 2025])
        ->dispatch('poste-tiers-reglement:ouvrir', ligneId: (int) $ligne->id, exercice: 2025)
        ->set('mode', ModePaiement::Cheque->value)
        ->set('compteBancaireId', null)
        ->call('enregistrer')
        ->assertHasErrors(['compteBancaireId' => 'required'])
        ->assertSee('Ce mode de règlement nécessite un compte bancaire.')
        ->assertNotDispatched('poste-tiers-reglement:enregistre');
});

test('enregistrer autorise un chèque reçu sans compte bancaire explicite', function (): void {
    [, $ligne] = creerPosteOuvertPourModaleReglement($this);

    Livewire::test(PosteTiersReglementModal::class, ['exercice' => 2025])
        ->dispatch('poste-tiers-reglement:ouvrir', ligneId: (int) $ligne->id, exercice: 2025)
        ->set('mode', ModePaiement::Cheque->value)
        ->set('compteBancaireId', null)
        ->call('enregistrer')
        ->assertHasNoErrors()
        ->assertDispatched('poste-tiers-reglement:enregistre');
});

test('enregistrer rejette un compte bancaire d une autre association dès la validation', function (): void {
    [, $ligne] = creerPosteOuvertPourModaleReglement($this);
    $autreAssociation = Association::factory()->create();
    $compteAutreAssociation = CompteBancaire::withoutGlobalScopes()->create([
        ...CompteBancaire::factory()->make()->toArray(),
        'association_id' => (int) $autreAssociation->id,
        'actif_recettes_depenses' => true,
        'saisie_automatisee' => false,
    ]);

    Livewire::test(PosteTiersReglementModal::class, ['exercice' => 2025])
        ->dispatch('poste-tiers-reglement:ouvrir', ligneId: (int) $ligne->id, exercice: 2025)
        ->set('mode', ModePaiement::Virement->value)
        ->set('compteBancaireId', (int) $compteAutreAssociation->id)
        ->call('enregistrer')
        ->assertHasErrors(['compteBancaireId'])
        ->assertNotDispatched('poste-tiers-reglement:enregistre');
});

test('enregistrer rejette un compte bancaire non sélectionnable dès la validation', function (
    string $attribut,
    bool $valeur,
): void {
    [, $ligne] = creerPosteOuvertPourModaleReglement($this);
    $compteNonSelectionnable = CompteBancaire::factory()->create([
        'association_id' => (int) TenantContext::currentId(),
        $attribut => $valeur,
    ]);

    Livewire::test(PosteTiersReglementModal::class, ['exercice' => 2025])
        ->dispatch('poste-tiers-reglement:ouvrir', ligneId: (int) $ligne->id, exercice: 2025)
        ->set('mode', ModePaiement::Virement->value)
        ->set('compteBancaireId', (int) $compteNonSelectionnable->id)
        ->call('enregistrer')
        ->assertHasErrors(['compteBancaireId'])
        ->assertNotDispatched('poste-tiers-reglement:enregistre');
})->with([
    'désactivé pour les recettes et dépenses' => ['actif_recettes_depenses', false],
    'alimenté par saisie automatisée' => ['saisie_automatisee', true],
]);

test('enregistrer affiche les messages métier pour un montant invalide', function (string $montant, string $message): void {
    [, $ligne] = creerPosteOuvertPourModaleReglement($this);

    Livewire::test(PosteTiersReglementModal::class, ['exercice' => 2025])
        ->dispatch('poste-tiers-reglement:ouvrir', ligneId: (int) $ligne->id, exercice: 2025)
        ->set('montant', $montant)
        ->set('mode', ModePaiement::Virement->value)
        ->set('compteBancaireId', (int) $this->compteBancaire->id)
        ->call('enregistrer')
        ->assertHasErrors(['montant'])
        ->assertSee($message);
})->with([
    'nul' => ['0,00', 'Le montant du règlement doit être strictement positif.'],
    'supérieur au solde' => ['100,01', 'Le montant du règlement ne peut pas dépasser le solde restant.'],
]);

test('enregistrer affiche le message métier pour une date hors exercice', function (): void {
    [, $ligne] = creerPosteOuvertPourModaleReglement($this);

    Livewire::test(PosteTiersReglementModal::class, ['exercice' => 2025])
        ->dispatch('poste-tiers-reglement:ouvrir', ligneId: (int) $ligne->id, exercice: 2025)
        ->set('dateReglement', '2026-09-01')
        ->set('mode', ModePaiement::Virement->value)
        ->set('compteBancaireId', (int) $this->compteBancaire->id)
        ->call('enregistrer')
        ->assertHasErrors(['dateReglement'])
        ->assertSee('La date du règlement doit appartenir à l’exercice 2025-2026.');
});

test('la modale d annulation confirme explicitement puis annule le règlement', function (): void {
    [, $ligne] = creerPosteOuvertPourModaleReglement($this);
    $t2 = $this->reglements->regler(new PosteTiersReglementData(
        ligneId: (int) $ligne->id,
        montantCentimes: 3000,
        date: CarbonImmutable::parse('2026-07-23'),
        mode: ModePaiement::Virement,
        compteBancaireId: (int) $this->compteBancaire->id,
        exercice: 2025,
    ));

    Livewire::test(AnnulationReglementTiersModal::class)
        ->dispatch('poste-tiers-reglement:annuler', transactionReglementId: (int) $t2->id)
        ->assertSet('transactionReglementId', (int) $t2->id)
        ->assertSet('dateReglement', '2026-07-23')
        ->assertSet('montantReglement', '30,00 €')
        ->call('annuler')
        ->assertHasNoErrors()
        ->assertDispatched('poste-tiers-reglement:annule')
        ->assertDispatched('poste-tiers-reglement-annulation-modal-close');

    expect(Transaction::withTrashed()->find((int) $t2->id))->toBeNull();
});

test('les vues utilisent les modales Bootstrap sans confirmation native', function (): void {
    $reglement = Livewire::test(PosteTiersReglementModal::class, ['exercice' => 2025])->html();
    $annulation = Livewire::test(AnnulationReglementTiersModal::class)->html();

    expect($reglement)->toContain('class="modal fade"')
        ->and($reglement)->toContain('wire:ignore.self')
        ->and($reglement)->toContain('poste-tiers-reglement-modal-open.window')
        ->and($reglement)->toContain('wire:model.live="mode"')
        ->and($annulation)->toContain('class="modal fade"')
        ->and($annulation)->toContain('Annuler le règlement')
        ->and($reglement.$annulation)->not->toContain('window.confirm');
});
