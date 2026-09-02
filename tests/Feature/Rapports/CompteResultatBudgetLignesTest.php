<?php

declare(strict_types=1);

// Le budget est la TROISIÈME source de lignes du compte de résultat, à côté
// des écritures de N et de celles de N-1. Un compte budgété qui n'a bougé ni
// en N ni en N-1 — typiquement un compte neuf ouvert pour une activité qui
// n'a pas encore démarré — n'avait aucune ligne, et son enveloppe manquait au
// total budget de la section.

use App\Models\Association;
use App\Models\BudgetLine;
use App\Models\Compte;
use App\Models\User;
use App\Services\RapportService;
use App\Tenant\TenantContext;

beforeEach(function (): void {
    $this->association = Association::factory()->create();
    $this->user = User::factory()->create();
    $this->user->associations()->attach($this->association->id, ['role' => 'admin', 'joined_at' => now()]);
    TenantContext::boot($this->association);
    session(['current_association_id' => $this->association->id]);
    session(['exercice_actif' => 2025]);
    $this->actingAs($this->user);
});

afterEach(function (): void {
    TenantContext::clear();
    session()->forget(['exercice_actif', 'current_association_id']);
});

/**
 * Ligne d'un compte dans une section du rapport, ou null s'il n'y figure pas.
 *
 * @param  list<array>  $section
 */
function ligneCompteDuRapport(array $section, int $compteId): ?array
{
    foreach ($section as $famille) {
        foreach ($famille['comptes'] as $compte) {
            if ((int) $compte['compte_id'] === $compteId) {
                return $compte;
            }
        }
    }

    return null;
}

it('une enveloppe de classe 6 sans aucun mouvement cree sa ligne du seul cote des charges', function (): void {
    $compte = Compte::factory()->numero('613A')->create([
        'association_id' => $this->association->id,
        'intitule' => 'Location salle',
    ]);
    BudgetLine::factory()->create([
        'association_id' => $this->association->id,
        'compte_id' => $compte->id,
        'exercice' => 2025,
        'operation_id' => null,
        'montant_prevu' => 1500.00,
    ]);

    $rapport = app(RapportService::class)->compteDeResultat(2025);

    $ligne = ligneCompteDuRapport($rapport['charges'], (int) $compte->id);

    expect($ligne)->not->toBeNull()
        ->and($ligne['compte_nom'])->toBe('Location salle')
        ->and((float) $ligne['montant_n'])->toBe(0.0)
        ->and($ligne['montant_n1'])->toBeNull()
        ->and((float) $ligne['budget'])->toBe(1500.0);

    // Le piège : la même carte de budget est passée aux DEUX sections. Sans
    // filtre par classe, ce compte de charge apparaîtrait aussi en produit et
    // fausserait les deux totaux.
    expect(ligneCompteDuRapport($rapport['produits'], (int) $compte->id))->toBeNull();
});

it('une enveloppe de classe 7 sans aucun mouvement cree sa ligne du seul cote des produits', function (): void {
    $compte = Compte::factory()->numero('756')->create([
        'association_id' => $this->association->id,
        'intitule' => 'Mécénat',
    ]);
    BudgetLine::factory()->create([
        'association_id' => $this->association->id,
        'compte_id' => $compte->id,
        'exercice' => 2025,
        'operation_id' => null,
        'montant_prevu' => 800.00,
    ]);

    $rapport = app(RapportService::class)->compteDeResultat(2025);

    $ligne = ligneCompteDuRapport($rapport['produits'], (int) $compte->id);

    expect($ligne)->not->toBeNull()
        ->and($ligne['compte_nom'])->toBe('Mécénat')
        ->and((float) $ligne['montant_n'])->toBe(0.0)
        ->and($ligne['montant_n1'])->toBeNull()
        ->and((float) $ligne['budget'])->toBe(800.0);

    expect(ligneCompteDuRapport($rapport['charges'], (int) $compte->id))->toBeNull();
});
