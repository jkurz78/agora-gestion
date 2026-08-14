<?php

declare(strict_types=1);

use App\Enums\StatutExercice;
use App\Livewire\Immobilisations\DotationsExercice;
use App\Models\Compte;
use App\Models\Exercice;
use App\Models\User;
use App\Services\Immobilisation\DotationService;
use App\Services\Immobilisation\ImmobilisationComptesSeeder;
use App\Tenant\TenantContext;
use Carbon\Carbon;
use Livewire\Livewire;

/**
 * Recette — le sélecteur d'exercice proposait `availableYears()`, soit cinq
 * années civiles dont l'année SUIVANTE. Or DotationService refuse déjà un
 * exercice non commencé (exerciceNonCommence) comme un exercice clôturé : la
 * liste offrait donc des choix qui ne pouvaient qu'échouer.
 *
 * Le sélecteur n'est pas supprimé pour autant : les dotations de l'exercice N
 * se génèrent une fois N terminé, donc en N+1, quand `current()` vaut déjà
 * N+1. Le figer sur l'exercice courant rendrait impossible le cas normal — et
 * le figer sur `current() - 1` interdirait tout rattrapage à une association
 * en retard de deux exercices. On garde le choix, on le restreint à ce que le
 * service accepte réellement.
 */
beforeEach(function (): void {
    $association = TenantContext::current();
    $user = User::factory()->create();
    $user->associations()->attach($association->id, ['role' => 'admin', 'joined_at' => now()]);
    $this->actingAs($user);

    Compte::factory()->create(['numero_pcg' => '401', 'classe' => 4, 'est_systeme' => true]);
    ImmobilisationComptesSeeder::seed();

    // Date fixe : l'exercice court du 1er sept. au 31 août. Au 15 octobre 2027,
    // l'exercice 2027 est commencé, le 2026 est terminé, le 2028 n'existe pas
    // encore — la situation exacte d'un comptable qui clôture.
    Carbon::setTestNow('2027-10-15');
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('ne propose jamais un exercice qui n’a pas commencé', function (): void {
    $generables = app(DotationService::class)->exercicesGenerables();

    expect($generables)->not->toContain(2028)
        ->and($generables)->toContain(2026);
});

it('ne propose pas un exercice clôturé', function (): void {
    Exercice::create(['annee' => 2026, 'statut' => StatutExercice::Cloture]);

    $generables = app(DotationService::class)->exercicesGenerables();

    expect($generables)->not->toContain(2026)
        ->and($generables)->toContain(2025);
});

it('propose l’exercice qu’on est en train de clôturer', function (): void {
    Exercice::create(['annee' => 2026, 'statut' => StatutExercice::Ouvert]);

    expect(app(DotationService::class)->exercicesGenerables())->toContain(2026);
});

it('l’écran n’offre que des exercices que le service accepte', function (): void {
    Exercice::create(['annee' => 2026, 'statut' => StatutExercice::Cloture]);

    $generables = app(DotationService::class)->exercicesGenerables();
    expect($generables)->not->toBeEmpty();

    $html = Livewire::test(DotationsExercice::class)->html();

    // L'exercice clôturé (2026) et celui qui n'a pas commencé (2028) ne doivent
    // pas figurer parmi les options ; ceux que le service accepte, si.
    expect($html)->not->toContain('<option value="2026"')
        ->and($html)->not->toContain('<option value="2028"');

    foreach ($generables as $annee) {
        expect($html)->toContain('<option value="'.$annee.'"');
    }
});

it('retombe sur un exercice générable quand celui par défaut est clôturé', function (): void {
    // Au 15/10/2027, l'exercice par défaut est 2026 (celui qu'on clôture).
    // S'il est déjà clôturé, l'écran ne doit pas s'ouvrir sur une valeur
    // absente de sa propre liste — le sélecteur afficherait alors la première
    // option tandis que le composant travaillerait sur une autre année.
    Exercice::create(['annee' => 2026, 'statut' => StatutExercice::Cloture]);

    $composant = Livewire::test(DotationsExercice::class);

    expect(app(DotationService::class)->exercicesGenerables())
        ->toContain($composant->get('exercice'));
});
