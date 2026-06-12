<?php

declare(strict_types=1);

use App\Enums\ModePaiement;
use App\Enums\StatutReglement;
use App\Enums\TypeTransaction;
use App\Models\Compte;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Services\Compta\EcritureGenerator;
use App\Services\Compta\EtatReglementResolver;
use App\Services\Compta\LettrageService;
use App\Tenant\TenantContext;
use Tests\Support\CreatesPartieDoubleContext;

uses(CreatesPartieDoubleContext::class);

beforeEach(function () {
    $this->setupPartieDoubleContext();
    $this->ecritureGen = app(EcritureGenerator::class);
    $this->resolver = app(EtatReglementResolver::class);
    $this->lettrageService = app(LettrageService::class);
});

afterEach(function () {
    TenantContext::clear();
});

test('resolve — miroir extourne Pointé (cancellation pure) → Pointe', function () {
    $tiers = Tiers::factory()->create(['association_id' => $this->association->id]);
    $compte411 = Compte::ofNumeroSysteme('411');

    // Origine de l'extourne : créance recette marquée extournée
    $origin = Transaction::factory()->create([
        'association_id' => $this->association->id,
        'type' => TypeTransaction::Recette,
        'montant_total' => 100.0,
        'statut_reglement' => StatutReglement::Pointe,
        'extournee_at' => now(),
    ]);

    // Ligne 411 D sur l'origine (créance)
    $ligneOrigin411 = TransactionLigne::create([
        'transaction_id' => (int) $origin->id,
        'compte_id' => (int) $compte411->id,
        'debit' => 100.0,
        'credit' => 0,
        'tiers_id' => (int) $tiers->id,
        'montant' => 0,
        'sous_categorie_id' => null,
    ]);

    // Ligne 706 C sur l'origine (produit)
    TransactionLigne::create([
        'transaction_id' => (int) $origin->id,
        'compte_id' => (int) $this->compte706->id,
        'debit' => 0,
        'credit' => 100.0,
        'tiers_id' => null,
        'montant' => 100.0,
        'sous_categorie_id' => null,
    ]);

    // Miroir extourne
    $mirror = Transaction::factory()->create([
        'association_id' => $this->association->id,
        'type' => TypeTransaction::Recette,
        'montant_total' => -100.0,
        'statut_reglement' => StatutReglement::Pointe,
        'type_ecriture' => 'extourne',
    ]);

    // Ligne 411 C sur le miroir (inverse de l'origine)
    $ligneMirror411 = TransactionLigne::create([
        'transaction_id' => (int) $mirror->id,
        'compte_id' => (int) $compte411->id,
        'debit' => 0,
        'credit' => 100.0,
        'tiers_id' => (int) $tiers->id,
        'montant' => 0,
        'sous_categorie_id' => null,
    ]);

    // Ligne 706 D sur le miroir (inverse)
    TransactionLigne::create([
        'transaction_id' => (int) $mirror->id,
        'compte_id' => (int) $this->compte706->id,
        'debit' => 100.0,
        'credit' => 0,
        'tiers_id' => null,
        'montant' => 100.0,
        'sous_categorie_id' => null,
    ]);

    // Cross-lettrage : origine 411 D ↔ miroir 411 C
    $this->lettrageService->lettrer(
        collect([$ligneOrigin411, $ligneMirror411]),
        null,
        'Test cross-lettrage annulation'
    );

    // Résolution miroir → Pointé (cancellation pair détectée via T2.extournee_at)
    expect($this->resolver->resolve($mirror))->toBe(StatutReglement::Pointe);
});

test('resolve — miroir recette extourne EnAttente avec T2 → statut dérivé', function () {
    $tiers = Tiers::factory()->create(['association_id' => $this->association->id]);
    $compte411 = Compte::ofNumeroSysteme('411');

    // Miroir extourne d'une recette — dette de remboursement (EnAttente)
    $mirror = Transaction::factory()->create([
        'association_id' => $this->association->id,
        'type' => TypeTransaction::Recette,
        'montant_total' => 150.0,
        'mode_paiement' => ModePaiement::Virement,
        'compte_id' => $this->compteBancaire->id,
        'statut_reglement' => StatutReglement::EnAttente,
        'type_ecriture' => 'extourne',
    ]);

    // Ligne 411 C (dette ouverte — argent à rembourser)
    TransactionLigne::create([
        'transaction_id' => (int) $mirror->id,
        'compte_id' => (int) $compte411->id,
        'debit' => 0,
        'credit' => 150.0,
        'tiers_id' => (int) $tiers->id,
        'montant' => 0,
        'sous_categorie_id' => null,
    ]);

    // Ligne 706 D (contrepartie)
    TransactionLigne::create([
        'transaction_id' => (int) $mirror->id,
        'compte_id' => (int) $this->compte706->id,
        'debit' => 150.0,
        'credit' => 0,
        'tiers_id' => null,
        'montant' => 150.0,
        'sous_categorie_id' => null,
    ]);

    // Avant règlement : EnAttente (ligne tiers ouverte)
    expect($this->resolver->resolve($mirror))->toBe(StatutReglement::EnAttente);

    // Règlement du miroir via pourReglement (crée T2)
    $this->ecritureGen->pourReglement(
        t1: $mirror,
        mode: ModePaiement::Virement,
        compteTresorerie: $this->compte512X,
        datePaiement: new DateTimeImmutable('2025-10-20'),
    );

    // Après règlement : statut dérivé non-EnAttente
    $resolved = $this->resolver->resolve($mirror->fresh());
    expect($resolved)->not->toBe(StatutReglement::EnAttente);
    // Virement → 512X (bancaire) → Recu (pas encore rapproché)
    expect($resolved)->toBe(StatutReglement::Recu);
});

test('resolve — miroir dépense extourne EnAttente avec T2 → statut dérivé', function () {
    $tiers = Tiers::factory()->create(['association_id' => $this->association->id]);
    $compte401 = Compte::ofNumeroSysteme('401');

    // Miroir extourne d'une dépense — argent à recevoir (EnAttente)
    $mirror = Transaction::factory()->create([
        'association_id' => $this->association->id,
        'type' => TypeTransaction::Depense,
        'montant_total' => 200.0,
        'mode_paiement' => ModePaiement::Virement,
        'compte_id' => $this->compteBancaire->id,
        'statut_reglement' => StatutReglement::EnAttente,
        'type_ecriture' => 'extourne',
    ]);

    // Ligne 401 D (argent à recevoir)
    TransactionLigne::create([
        'transaction_id' => (int) $mirror->id,
        'compte_id' => (int) $compte401->id,
        'debit' => 200.0,
        'credit' => 0,
        'tiers_id' => (int) $tiers->id,
        'montant' => 0,
        'sous_categorie_id' => null,
    ]);

    // Ligne 606 C (contrepartie)
    TransactionLigne::create([
        'transaction_id' => (int) $mirror->id,
        'compte_id' => (int) $this->compte606->id,
        'debit' => 0,
        'credit' => 200.0,
        'tiers_id' => null,
        'montant' => 200.0,
        'sous_categorie_id' => null,
    ]);

    // Avant règlement : EnAttente
    expect($this->resolver->resolve($mirror))->toBe(StatutReglement::EnAttente);

    // Règlement
    $this->ecritureGen->pourReglement(
        t1: $mirror,
        mode: ModePaiement::Virement,
        compteTresorerie: $this->compte512X,
        datePaiement: new DateTimeImmutable('2025-10-20'),
    );

    // Après règlement : Recu (virement → 512X, pas encore rapproché)
    expect($this->resolver->resolve($mirror->fresh()))->toBe(StatutReglement::Recu);
});
