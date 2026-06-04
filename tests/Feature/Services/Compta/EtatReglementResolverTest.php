<?php

declare(strict_types=1);

use App\Enums\ModePaiement;
use App\Enums\StatutReglement;
use App\Enums\TypeTransaction;
use App\Models\RapprochementBancaire;
use App\Models\RemiseBancaire;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Services\Compta\EtatReglementResolver;
use App\Services\ReglementOperationService;
use App\Services\RemiseBancaireService;
use App\Services\TransactionService;
use Tests\Support\CreatesPartieDoubleContext;

uses(CreatesPartieDoubleContext::class);

beforeEach(function () {
    $this->setupPartieDoubleContext();
    $this->service = app(TransactionService::class);
    $this->resolver = app(EtatReglementResolver::class);
    $this->tiers = Tiers::factory()->create(['association_id' => $this->association->id]);
});

function recetteData(object $ctx, ?string $mode): array
{
    return [
        'data' => [
            'type' => TypeTransaction::Recette->value,
            'date' => '2025-10-15',
            'libelle' => 'Recette test',
            'montant_total' => '100.00',
            'mode_paiement' => $mode,
            'tiers_id' => $ctx->tiers->id,
            'compte_id' => $mode === null ? null : $ctx->compteBancaire->id,
        ],
        'lignes' => [[
            'sous_categorie_id' => $ctx->sc706->id,
            'montant' => '100.00',
            'operation_id' => null,
            'seance' => null,
            'notes' => null,
        ]],
    ];
}

it('recette créance (411 non lettré) → EnAttente (ouvert)', function () {
    ['data' => $data, 'lignes' => $lignes] = recetteData($this, null);
    $t1 = $this->service->create($data, $lignes);

    expect($this->resolver->resolve($t1->fresh()))->toBe(StatutReglement::EnAttente);
});

it('recette chèque comptant (411 lettré, 5112 non remis) → EnMain (à remettre)', function () {
    ['data' => $data, 'lignes' => $lignes] = recetteData($this, ModePaiement::Cheque->value);
    $t1 = $this->service->create($data, $lignes);

    expect($this->resolver->resolve($t1->fresh()))->toBe(StatutReglement::EnMain);
});

it('recette virement comptant (411 lettré, 512X direct non rapproché) → Recu (dénoué)', function () {
    ['data' => $data, 'lignes' => $lignes] = recetteData($this, ModePaiement::Virement->value);
    $t1 = $this->service->create($data, $lignes);

    expect($this->resolver->resolve($t1->fresh()))->toBe(StatutReglement::Recu);
});

it('fallback : transaction sans ligne PD (legacy) → renvoie la colonne stockée', function () {
    $legacy = Transaction::factory()->create([
        'association_id' => $this->association->id,
        'type' => TypeTransaction::Recette->value,
        'statut_reglement' => StatutReglement::Recu->value,
        'compte_id' => $this->compteBancaire->id,
    ]);

    expect($this->resolver->resolve($legacy))->toBe(StatutReglement::Recu);
});

function depenseData(object $ctx, ?string $mode): array
{
    return [
        'data' => [
            'type' => TypeTransaction::Depense->value,
            'date' => '2025-10-15',
            'libelle' => 'Dépense test',
            'montant_total' => '50.00',
            'mode_paiement' => $mode,
            'tiers_id' => $ctx->tiers->id,
            'compte_id' => $mode === null ? null : $ctx->compteBancaire->id,
        ],
        'lignes' => [[
            'sous_categorie_id' => $ctx->sc606->id,
            'montant' => '50.00',
            'operation_id' => null,
            'seance' => null,
            'notes' => null,
        ]],
    ];
}

it('dépense dette (401 non lettré) → EnAttente (dû)', function () {
    ['data' => $data, 'lignes' => $lignes] = depenseData($this, null);
    $t1 = $this->service->create($data, $lignes);

    expect($this->resolver->resolve($t1->fresh()))->toBe(StatutReglement::EnAttente);
});

it('dépense réglée par virement (401 lettré, 512X non rapproché) → Recu (réglé)', function () {
    ['data' => $data, 'lignes' => $lignes] = depenseData($this, ModePaiement::Virement->value);
    $t1 = $this->service->create($data, $lignes);

    expect($this->resolver->resolve($t1->fresh()))->toBe(StatutReglement::Recu);
});

it('recette chèque remis en banque (5112 lettré vers T4 512X non rapproché) → Recu (remis)', function () {
    ['data' => $data, 'lignes' => $lignes] = recetteData($this, ModePaiement::Cheque->value);
    $t1 = $this->service->create($data, $lignes);

    $remise = RemiseBancaire::create([
        'association_id' => $this->association->id,
        'numero' => 2001,
        'date' => '2025-10-20',
        'mode_paiement' => ModePaiement::Cheque->value,
        'compte_cible_id' => $this->compteBancaire->id,
        'libelle' => 'Remise resolver test',
        'saisi_par' => $this->user->id,
    ]);
    app(RemiseBancaireService::class)->comptabiliser($remise, [$t1->id]);

    expect($this->resolver->resolve($t1->fresh()))->toBe(StatutReglement::Recu);
});

it('recette virement rapprochée (512X portant rapprochement_id) → Pointe', function () {
    ['data' => $data, 'lignes' => $lignes] = recetteData($this, ModePaiement::Virement->value);
    $t1 = $this->service->create($data, $lignes);

    // La T2 séparée porte le 512X ; on la marque rapprochée.
    $compte411 = compteSysteme('411');
    $t2 = app(ReglementOperationService::class)->trouverEncaissementT2($t1->fresh(), $compte411);
    expect($t2)->not->toBeNull('[précondition] T2 séparée doit exister pour un virement comptant');

    $rappro = RapprochementBancaire::factory()->create([
        'association_id' => $this->association->id,
        'compte_id' => $this->compteBancaire->id,
        'saisi_par' => $this->user->id,
    ]);
    $t2->rapprochement_id = $rappro->id;
    $t2->save();

    expect($this->resolver->resolve($t1->fresh()))->toBe(StatutReglement::Pointe);
});

it('recette espèces comptant (530 en main, pas de 512X) → EnMain', function () {
    ['data' => $data, 'lignes' => $lignes] = recetteData($this, ModePaiement::Especes->value);
    $t1 = $this->service->create($data, $lignes);

    expect($this->resolver->resolve($t1->fresh()))->toBe(StatutReglement::EnMain);
});

it('syncer persiste le statut dérivé quand il diffère du miroir', function () {
    ['data' => $data, 'lignes' => $lignes] = recetteData($this, ModePaiement::Cheque->value);
    $t1 = $this->service->create($data, $lignes);

    // Forcer un miroir périmé (chèque en main mais colonne = Recu).
    $t1->forceFill(['statut_reglement' => StatutReglement::Recu->value])->save();

    $this->resolver->syncer($t1);

    expect($t1->fresh()->statut_reglement)->toBe(StatutReglement::EnMain);
});

it('syncer est idempotent (deux appels = même résultat, pas de drift)', function () {
    ['data' => $data, 'lignes' => $lignes] = recetteData($this, ModePaiement::Cheque->value);
    $t1 = $this->service->create($data, $lignes);

    $this->resolver->syncer($t1);
    $premier = $t1->fresh()->statut_reglement;
    $this->resolver->syncer($t1->fresh());
    $second = $t1->fresh()->statut_reglement;

    expect($second)->toBe($premier);
});

it('syncer est un no-op en mode legacy (use_partie_double=false)', function () {
    config()->set('compta.use_partie_double', false);

    $t1 = Transaction::factory()->create([
        'association_id' => $this->association->id,
        'type' => TypeTransaction::Recette->value,
        'statut_reglement' => StatutReglement::Recu->value,
    ]);

    $this->resolver->syncer($t1);

    expect($t1->fresh()->statut_reglement)->toBe(StatutReglement::Recu);
});
