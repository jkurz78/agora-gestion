<?php

declare(strict_types=1);

/**
 * Régression T2 Zombie — TransactionService::update() branche libre.
 *
 * Bug : quand on modifie le montant (ou le mode) d'une T1 sans remettre
 * mode_paiement à null, la T2 existante n'est PAS supprimée avant que
 * enrichirPartieDouble() en recrée une nouvelle.  L'ancienne T2 survit
 * comme zombie avec l'ancien montant/mode.
 *
 * Fix attendu : appeler supprimerT2SiExiste($transaction) dans la branche
 * else (non-locked) de update(), AVANT autoDelettrerLignesAvantUpdate().
 */

use App\Enums\ModePaiement;
use App\Enums\TypeTransaction;
use App\Models\Compte;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Services\ReglementOperationService;
use App\Services\TransactionService;
use App\Tenant\TenantContext;
use Tests\Support\CreatesPartieDoubleContext;

uses(CreatesPartieDoubleContext::class);

// ---------------------------------------------------------------------------
// Setup partagé
// ---------------------------------------------------------------------------

beforeEach(function () {
    $this->setupPartieDoubleContext();

    // Tiers requis pour l'enrichissement partie double (411/401)
    $this->tiers = Tiers::factory()->create([
        'association_id' => $this->association->id,
    ]);

    $this->service = app(TransactionService::class);
    $this->reglementService = app(ReglementOperationService::class);
});

afterEach(function () {
    TenantContext::clear();
});

// ---------------------------------------------------------------------------
// Helper — créer une recette chèque via service::create (T1+T2 séparées)
// ---------------------------------------------------------------------------

function creerRecetteZombie(object $ctx, float $montant = 100.0): Transaction
{
    $data = [
        'type' => TypeTransaction::Recette->value,
        'date' => '2025-10-15',
        'libelle' => 'Recette zombie test',
        'montant_total' => (string) $montant,
        'mode_paiement' => ModePaiement::Cheque->value,
        'tiers_id' => $ctx->tiers->id,
        'compte_id' => $ctx->compteBancaire->id,
    ];
    $lignes = [[
        'sous_categorie_id' => $ctx->sc706->id,
        'montant' => (string) $montant,
        'operation_id' => null,
        'seance' => null,
        'notes' => null,
    ]];

    return $ctx->service->create($data, $lignes);
}

// ---------------------------------------------------------------------------
// Helper — créer une dépense virement via service::create (T1+T2 séparées)
// ---------------------------------------------------------------------------

function creerDepenseZombie(object $ctx, float $montant = 100.0): Transaction
{
    $data = [
        'type' => TypeTransaction::Depense->value,
        'date' => '2025-10-15',
        'libelle' => 'Dépense zombie test',
        'montant_total' => (string) $montant,
        'mode_paiement' => ModePaiement::Virement->value,
        'tiers_id' => $ctx->tiers->id,
        'compte_id' => $ctx->compteBancaire->id,
    ];
    $lignes = [[
        'sous_categorie_id' => $ctx->sc606->id,
        'montant' => (string) $montant,
        'operation_id' => null,
        'seance' => null,
        'notes' => null,
    ]];

    return $ctx->service->create($data, $lignes);
}

// ---------------------------------------------------------------------------
// Scénario 1 — Recette : update montant → pas de zombie T2
// ---------------------------------------------------------------------------

test('update montant T1 recette supprime ancien T2 et recrée avec nouveau montant', function () {
    $t1 = creerRecetteZombie($this, 100.0);

    $compte411 = Compte::where('association_id', $this->association->id)
        ->where('numero_pcg', '411')->firstOrFail();

    // Précondition : T2 existe à 100
    $t2Avant = $this->reglementService->trouverEncaissementT2($t1);
    expect($t2Avant)->not()->toBeNull('[Précond] T2 doit exister avant update');
    expect((float) $t2Avant->montant_total)->toBe(100.0, '[Précond] T2 montant = 100');

    $t2IdAvant = (int) $t2Avant->id;

    // Action : changer le montant de 100 → 150, mode inchangé (Cheque)
    $t1 = $this->service->update($t1, [
        'type' => TypeTransaction::Recette->value,
        'date' => '2025-10-15',
        'libelle' => 'Recette zombie test',
        'montant_total' => '150.00',
        'mode_paiement' => ModePaiement::Cheque->value,
        'tiers_id' => $this->tiers->id,
        'compte_id' => $this->compteBancaire->id,
    ], [[
        'id' => null,
        'sous_categorie_id' => $this->sc706->id,
        'montant' => '150.00',
        'operation_id' => null,
        'seance' => null,
        'notes' => null,
    ]]);

    // Trouver la nouvelle T2 via le lettrage 411
    $ligne411T1 = TransactionLigne::where('transaction_id', $t1->id)
        ->where('compte_id', $compte411->id)
        ->whereNotNull('lettrage_code')
        ->firstOrFail();

    $ligne411T2 = TransactionLigne::where('lettrage_code', $ligne411T1->lettrage_code)
        ->where('compte_id', $compte411->id)
        ->where('transaction_id', '!=', (int) $t1->id)
        ->first();

    expect($ligne411T2)->not()->toBeNull('Une T2 doit exister après update');

    $t2Apres = Transaction::find($ligne411T2->transaction_id);
    expect($t2Apres)->not()->toBeNull('T2 trouvable en base');

    // La T2 recréée reflète le nouveau montant
    expect((float) $t2Apres->montant_total)->toBe(150.0, 'T2 doit avoir le nouveau montant 150');

    // L'ancienne T2 (zombie) doit avoir été supprimée (force-deleted)
    $zombieT2 = Transaction::withTrashed()->find($t2IdAvant);
    expect($zombieT2)->toBeNull('L\'ancienne T2 (zombie) doit avoir été force-deleted, pas juste soft-deleted');

    // Il doit y avoir exactement 1 T2 (pas de doublon zombie)
    $compteAll411T2Lignes = TransactionLigne::where('compte_id', $compte411->id)
        ->where('transaction_id', '!=', (int) $t1->id)
        ->count();
    expect($compteAll411T2Lignes)->toBe(1, 'Exactement 1 ligne 411 sur une T2 (pas de zombie)');
});

// ---------------------------------------------------------------------------
// Scénario 2 — Dépense : update montant → pas de zombie T2
// ---------------------------------------------------------------------------

test('update montant T1 dépense supprime ancien T2 et recrée', function () {
    $t1 = creerDepenseZombie($this, 100.0);

    $compte401 = Compte::where('association_id', $this->association->id)
        ->where('numero_pcg', '401')->firstOrFail();

    // Précondition : T2 règlement existe
    $t2Avant = $this->reglementService->trouverReglementT2($t1);
    expect($t2Avant)->not()->toBeNull('[Précond] T2 doit exister avant update');
    expect((float) $t2Avant->montant_total)->toBe(100.0, '[Précond] T2 montant = 100');

    $t2IdAvant = (int) $t2Avant->id;

    // Action : changer le montant de 100 → 250, mode inchangé (Virement)
    $t1 = $this->service->update($t1, [
        'type' => TypeTransaction::Depense->value,
        'date' => '2025-10-15',
        'libelle' => 'Dépense zombie test',
        'montant_total' => '250.00',
        'mode_paiement' => ModePaiement::Virement->value,
        'tiers_id' => $this->tiers->id,
        'compte_id' => $this->compteBancaire->id,
    ], [[
        'id' => null,
        'sous_categorie_id' => $this->sc606->id,
        'montant' => '250.00',
        'operation_id' => null,
        'seance' => null,
        'notes' => null,
    ]]);

    // Trouver la nouvelle T2 via le lettrage 401
    $ligne401T1 = TransactionLigne::where('transaction_id', $t1->id)
        ->where('compte_id', $compte401->id)
        ->whereNotNull('lettrage_code')
        ->firstOrFail();

    $ligne401T2 = TransactionLigne::where('lettrage_code', $ligne401T1->lettrage_code)
        ->where('compte_id', $compte401->id)
        ->where('transaction_id', '!=', (int) $t1->id)
        ->first();

    expect($ligne401T2)->not()->toBeNull('Une T2 règlement doit exister après update');

    $t2Apres = Transaction::find($ligne401T2->transaction_id);
    expect($t2Apres)->not()->toBeNull('T2 trouvable en base');

    // La T2 recréée reflète le nouveau montant
    expect((float) $t2Apres->montant_total)->toBe(250.0, 'T2 doit avoir le nouveau montant 250');

    // L'ancienne T2 (zombie) doit avoir été force-deleted
    $zombieT2 = Transaction::withTrashed()->find($t2IdAvant);
    expect($zombieT2)->toBeNull('L\'ancienne T2 (zombie) doit avoir été force-deleted');

    // Exactement 1 ligne 401 sur une T2
    $compteAll401T2Lignes = TransactionLigne::where('compte_id', $compte401->id)
        ->where('transaction_id', '!=', (int) $t1->id)
        ->count();
    expect($compteAll401T2Lignes)->toBe(1, 'Exactement 1 ligne 401 sur une T2 (pas de zombie)');
});

// ---------------------------------------------------------------------------
// Scénario 3 — Recette : update mode paiement → T2 recréée avec nouveau mode
// ---------------------------------------------------------------------------

test('update mode paiement T1 recrée T2 avec nouveau mode', function () {
    // T1 créée avec mode Cheque (T2 portage 5112)
    $t1 = creerRecetteZombie($this, 100.0);

    $compte411 = Compte::where('association_id', $this->association->id)
        ->where('numero_pcg', '411')->firstOrFail();
    $compte5112 = Compte::where('association_id', $this->association->id)
        ->where('numero_pcg', '5112')->firstOrFail();

    // Précondition : T2 chèque existe (portage 5112)
    $t2Avant = $this->reglementService->trouverEncaissementT2($t1);
    expect($t2Avant)->not()->toBeNull('[Précond] T2 chèque doit exister avant update');

    $t2IdAvant = (int) $t2Avant->id;

    // Vérifier que T2 est bien en mode chèque (ligne 5112)
    $ligne5112Avant = TransactionLigne::where('transaction_id', $t2IdAvant)
        ->where('compte_id', $compte5112->id)
        ->first();
    expect($ligne5112Avant)->not()->toBeNull('[Précond] T2 doit avoir une ligne 5112 (portage chèque)');

    // Action : changer le mode de Cheque → Virement (montant inchangé)
    $t1 = $this->service->update($t1, [
        'type' => TypeTransaction::Recette->value,
        'date' => '2025-10-15',
        'libelle' => 'Recette zombie test',
        'montant_total' => '100.00',
        'mode_paiement' => ModePaiement::Virement->value,  // ← mode changé
        'tiers_id' => $this->tiers->id,
        'compte_id' => $this->compteBancaire->id,
    ], [[
        'id' => null,
        'sous_categorie_id' => $this->sc706->id,
        'montant' => '100.00',
        'operation_id' => null,
        'seance' => null,
        'notes' => null,
    ]]);

    // L'ancienne T2 chèque (zombie) doit avoir été force-deleted
    $zombieT2 = Transaction::withTrashed()->find($t2IdAvant);
    expect($zombieT2)->toBeNull('L\'ancienne T2 chèque (zombie) doit avoir été force-deleted');

    // Trouver la nouvelle T2 via le lettrage 411
    $ligne411T1 = TransactionLigne::where('transaction_id', $t1->id)
        ->where('compte_id', $compte411->id)
        ->whereNotNull('lettrage_code')
        ->first();

    expect($ligne411T1)->not()->toBeNull('T1 doit avoir une ligne 411 lettrée après update');

    $ligne411T2Nouvelle = TransactionLigne::where('lettrage_code', $ligne411T1->lettrage_code)
        ->where('compte_id', $compte411->id)
        ->where('transaction_id', '!=', (int) $t1->id)
        ->first();

    expect($ligne411T2Nouvelle)->not()->toBeNull('Une nouvelle T2 doit exister après changement de mode');

    // La nouvelle T2 doit être en mode Virement (ligne 512X, pas 5112)
    $t2Nouvelle = Transaction::findOrFail($ligne411T2Nouvelle->transaction_id);
    $ligne5112Apres = TransactionLigne::where('transaction_id', $t2Nouvelle->id)
        ->where('compte_id', $compte5112->id)
        ->first();
    expect($ligne5112Apres)->toBeNull('La T2 virement ne doit PAS avoir de ligne 5112 (chèque)');

    // La nouvelle T2 doit avoir une ligne 512X (virement)
    $ligne512XApres = TransactionLigne::where('transaction_id', $t2Nouvelle->id)
        ->where('compte_id', $this->compte512X->id)
        ->first();
    expect($ligne512XApres)->not()->toBeNull('La T2 virement doit avoir une ligne 512X');

    // Exactement 1 T2 (pas de doublon zombie chèque)
    $compteAll411T2Lignes = TransactionLigne::where('compte_id', $compte411->id)
        ->where('transaction_id', '!=', (int) $t1->id)
        ->count();
    expect($compteAll411T2Lignes)->toBe(1, 'Exactement 1 ligne 411 sur une T2 (pas de zombie chèque)');
});
