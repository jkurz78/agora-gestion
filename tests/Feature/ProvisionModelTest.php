<?php

declare(strict_types=1);

use App\Enums\CasProvision;
use App\Enums\StatutExercice;
use App\Models\Association;
use App\Models\Compte;
use App\Models\Exercice;
use App\Models\Operation;
use App\Models\Provision;
use App\Models\User;
use App\Tenant\TenantContext;

beforeEach(function () {
    $this->association = Association::factory()->create();
    $this->user = User::factory()->create();
    $this->user->associations()->attach($this->association->id, ['role' => 'admin', 'joined_at' => now()]);
    TenantContext::boot($this->association);
    session(['current_association_id' => $this->association->id]);
    $this->actingAs($this->user);

    Exercice::create(['association_id' => $this->association->id, 'annee' => 2025, 'statut' => StatutExercice::Ouvert]);
    Exercice::create(['association_id' => $this->association->id, 'annee' => 2026, 'statut' => StatutExercice::Ouvert]);
    session(['exercice_actif' => 2025]);
});

/**
 * Attributs minimaux d'un rattachement valide, à compléter par l'appelant.
 *
 * @param  array<string, mixed>  $attributs
 * @return array<string, mixed>
 */
function provisionAttributs(array $attributs = []): array
{
    return array_merge([
        'exercice' => 2025,
        'montant' => 175.0,
        'saisi_par' => auth()->id(),
    ], $attributs);
}

// ── Le cas se déduit (D2) ─────────────────────────────────────────────────────

it('déduit le cas d’un rattachement adossé de la classe de sa ligne d’origine', function (int $classe, CasProvision $attendu): void {
    $ligne = $this->ligneOrigine($classe);

    $provision = Provision::create(provisionAttributs(['transaction_ligne_id' => $ligne->id]));

    expect($provision->cas())->toBe($attendu)
        ->and($provision->estAdossee())->toBeTrue();
})->with([
    'classe 7' => [7, CasProvision::ProduitConstateDavance],
    'classe 6' => [6, CasProvision::ChargeConstateeDavance],
]);

it('déduit le cas d’un rattachement libre de la classe du compte saisi', function (int $classe, CasProvision $attendu): void {
    $compte = ($classe === 6 ? Compte::factory()->depense() : Compte::factory()->recette())
        ->create(['association_id' => $this->association->id]);

    $provision = Provision::create(provisionAttributs([
        'compte_id' => $compte->id,
        'libelle' => 'Saisie libre',
    ]));

    expect($provision->cas())->toBe($attendu)
        ->and($provision->estAdossee())->toBeFalse();
})->with([
    'classe 6' => [6, CasProvision::FactureNonParvenue],
    'classe 7' => [7, CasProvision::ProduitARecevoir],
]);

it('lève une erreur explicite quand le compte de résultat est introuvable', function (): void {
    $compte = Compte::factory()->recette()->create(['association_id' => $this->association->id]);
    $provision = Provision::create(provisionAttributs(['compte_id' => $compte->id]));

    $compte->delete(); // suppression logique : la relation ne le retrouve plus

    expect(fn () => $provision->fresh()->cas())->toThrow(RuntimeException::class, 'aucun compte de résultat résolu');
});

// ── Origine héritée ───────────────────────────────────────────────────────────

it('un rattachement adossé hérite l’opération et la séance de sa ligne d’origine', function (): void {
    $operation = Operation::factory()->create();
    $ligne = $this->ligneOrigine(7, 175.0, (int) $operation->id, 3);

    $provision = Provision::create(provisionAttributs(['transaction_ligne_id' => $ligne->id]));

    expect($provision->operationEffectiveId())->toBe((int) $operation->id)
        ->and($provision->seanceEffective())->toBe(3);
});

it('un rattachement libre porte lui-même son opération et sa séance', function (): void {
    $operation = Operation::factory()->create();
    $compte = Compte::factory()->depense()->create(['association_id' => $this->association->id]);

    $provision = Provision::create(provisionAttributs([
        'compte_id' => $compte->id,
        'operation_id' => $operation->id,
        'seance' => 2,
    ]));

    expect($provision->operationEffectiveId())->toBe((int) $operation->id)
        ->and($provision->seanceEffective())->toBe(2);
});

it('un rattachement sans opération ni séance les rend nuls', function (): void {
    $ligne = $this->ligneOrigine(7);

    $provision = Provision::create(provisionAttributs(['transaction_ligne_id' => $ligne->id]));

    expect($provision->operationEffectiveId())->toBeNull()
        ->and($provision->seanceEffective())->toBeNull();
});

// ── Invariant des deux colonnes (D5, AC-4) ────────────────────────────────────

it('AC-4 — refuse un rattachement qui n’a ni ligne d’origine ni compte', function (): void {
    expect(fn () => Provision::create(provisionAttributs()))
        ->toThrow(InvalidArgumentException::class, 'jamais les deux ni aucun');
});

it('AC-4 — refuse un rattachement qui a à la fois une ligne d’origine et un compte', function (): void {
    $ligne = $this->ligneOrigine(7);
    $compte = Compte::factory()->recette()->create(['association_id' => $this->association->id]);

    expect(fn () => Provision::create(provisionAttributs([
        'transaction_ligne_id' => $ligne->id,
        'compte_id' => $compte->id,
    ])))->toThrow(InvalidArgumentException::class, 'jamais les deux ni aucun');
});

// ── Montant tout ou rien (D12) ────────────────────────────────────────────────

it('refuse un montant nul ou négatif (D12)', function (float $montant): void {
    $ligne = $this->ligneOrigine(7);

    expect(fn () => Provision::create(provisionAttributs([
        'transaction_ligne_id' => $ligne->id,
        'montant' => $montant,
    ])))->toThrow(InvalidArgumentException::class, 'strictement positif');
})->with([
    'zéro' => [0.0],
    'négatif' => [-175.0],
]);

// ── Classe du compte de résultat : 6 ou 7 ─────────────────────────────────────

it('refuse un rattachement libre sur un compte qui n’est ni de classe 6 ni de classe 7', function (): void {
    $compte = Compte::factory()->numero('4119')->create(['association_id' => $this->association->id]);

    expect(fn () => Provision::create(provisionAttributs(['compte_id' => $compte->id])))
        ->toThrow(InvalidArgumentException::class, 'classe 4 ne porte aucun cas');
});

it('refuse un rattachement adossé à une ligne dont le compte n’est ni de classe 6 ni de classe 7', function (): void {
    $ligne = $this->ligneOrigine(7);
    $compteClasse4 = Compte::factory()->numero('4118')->create(['association_id' => $this->association->id]);
    $ligne->update(['compte_id' => $compteClasse4->id]);

    expect(fn () => Provision::create(provisionAttributs(['transaction_ligne_id' => $ligne->id])))
        ->toThrow(InvalidArgumentException::class, 'classe 4 ne porte aucun cas');
});

it('refuse un compte ou une ligne d’origine introuvable', function (): void {
    expect(fn () => Provision::create(provisionAttributs(['compte_id' => 999_999])))
        ->toThrow(InvalidArgumentException::class, 'introuvable');

    expect(fn () => Provision::create(provisionAttributs(['transaction_ligne_id' => 999_999])))
        ->toThrow(InvalidArgumentException::class, 'introuvable');
});

it('refuse le compte d’une autre association', function (): void {
    $autre = Association::factory()->create();
    $compteEtranger = Compte::factory()->recette()->create(['association_id' => $autre->id]);

    expect(fn () => Provision::create(provisionAttributs(['compte_id' => $compteEtranger->id])))
        ->toThrow(InvalidArgumentException::class, 'introuvable');
});

it('refuse de repointer un rattachement existant vers un compte de classe 4', function (): void {
    $compte = Compte::factory()->recette()->create(['association_id' => $this->association->id]);
    $provision = Provision::create(provisionAttributs(['compte_id' => $compte->id]));
    $compteClasse4 = Compte::factory()->numero('4117')->create(['association_id' => $this->association->id]);

    expect(fn () => $provision->update(['compte_id' => $compteClasse4->id]))
        ->toThrow(InvalidArgumentException::class, 'classe 4 ne porte aucun cas');
});

it('refuse de repointer un rattachement adossé vers une ligne dont le compte est de classe 4', function (): void {
    $provision = Provision::create(provisionAttributs(['transaction_ligne_id' => $this->ligneOrigine(7)->id]));

    $autreLigne = $this->ligneOrigine(7);
    $compteClasse4 = Compte::factory()->numero('4116')->create(['association_id' => $this->association->id]);
    $autreLigne->update(['compte_id' => $compteClasse4->id]);

    expect(fn () => $provision->update(['transaction_ligne_id' => $autreLigne->id]))
        ->toThrow(InvalidArgumentException::class, 'classe 4 ne porte aucun cas');
});

it('ne revalide pas la classe quand l’origine ne change pas', function (): void {
    // Un compte supprimé après coup ne doit pas empêcher de joindre une pièce
    // à un rattachement qui existe déjà.
    $compte = Compte::factory()->recette()->create(['association_id' => $this->association->id]);
    $provision = Provision::create(provisionAttributs(['compte_id' => $compte->id]));
    $compte->delete();

    $provision->update(['piece_jointe_path' => 'piece-jointe.pdf']);

    expect($provision->fresh()->piece_jointe_path)->toBe('piece-jointe.pdf');
});

// ── Cloisonnement par association ─────────────────────────────────────────────

it('un rattachement n’est visible que de son association, qui est posée à la création', function (): void {
    $ligne = $this->ligneOrigine(7);

    // association_id volontairement omis : c'est la création qui le pose.
    $provision = Provision::create(provisionAttributs(['transaction_ligne_id' => $ligne->id]));

    expect((int) $provision->association_id)->toBe((int) $this->association->id)
        ->and(Provision::count())->toBe(1);

    TenantContext::boot(Association::factory()->create());

    expect(Provision::count())->toBe(0)
        ->and(Provision::withoutGlobalScopes()->count())->toBe(1);
});

// ── Factory et scope ──────────────────────────────────────────────────────────

it('la factory fabrique un rattachement adossé sans compte propre, pour le montant de sa ligne', function (int $classe, float $montant, CasProvision $attendu): void {
    $ligne = $this->ligneOrigine($classe, $montant);

    $provision = Provision::factory()->adossee($ligne)->create();

    expect($provision->estAdossee())->toBeTrue()
        ->and($provision->compte_id)->toBeNull()
        ->and((int) $provision->transaction_ligne_id)->toBe((int) $ligne->id)
        ->and((float) $provision->montant)->toBe($montant)
        ->and($provision->cas())->toBe($attendu);
})->with([
    'produit (crédit)' => [7, 175.0, CasProvision::ProduitConstateDavance],
    'charge (débit)' => [6, 100.5, CasProvision::ChargeConstateeDavance],
]);

it('le scope avecCompteResultat charge la source du compte, adossée ou libre', function (): void {
    Provision::factory()->adossee($this->ligneOrigine(7))->create();
    Provision::factory()->create(); // libre, compte de la factory

    $provisions = Provision::avecCompteResultat()->get();

    expect($provisions)->toHaveCount(2);
    $adossee = $provisions->first(fn (Provision $p) => $p->estAdossee());
    $libre = $provisions->first(fn (Provision $p) => ! $p->estAdossee());

    expect($adossee->relationLoaded('ligne'))->toBeTrue()
        ->and($adossee->ligne->relationLoaded('compte'))->toBeTrue()
        ->and($libre->relationLoaded('compte'))->toBeTrue();
});
