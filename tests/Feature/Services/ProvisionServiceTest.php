<?php

declare(strict_types=1);

use App\Enums\StatutExercice;
use App\Models\Association;
use App\Models\Compte;
use App\Models\Exercice;
use App\Models\Provision;
use App\Models\User;
use App\Services\ProvisionService;
use App\Tenant\TenantContext;
use Illuminate\Support\Facades\Log;

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

    $this->service = app(ProvisionService::class);
});

/**
 * Rattache à l'exercice 2025 une ligne d'origine de la classe donnée, pour son montant.
 */
function rattacher(object $test, int $classe, float $montant): Provision
{
    $ligne = $test->ligneOrigine($classe, $montant);

    return Provision::create([
        'exercice' => 2025,
        'transaction_ligne_id' => $ligne->id,
        'montant' => $montant,
        'saisi_par' => auth()->id(),
    ]);
}

it('lit le compte de chaque rattachement via son origine', function (): void {
    $pca = rattacher($this, 7, 175.0);
    $cca = rattacher($this, 6, 100.0);

    $recap = $this->service->provisionsExercice(2025)->keyBy('id');

    expect($recap[$pca->id]['compte_id'])->toBe((int) $pca->ligne->compte_id)
        ->and($recap[$pca->id]['compte_nom'])->toBe($pca->ligne->compte->intitule)
        ->and($recap[$cca->id]['compte_id'])->toBe((int) $cca->ligne->compte_id)
        ->and($recap[$cca->id]['compte_nom'])->toBe($cca->ligne->compte->intitule);
});

it('porte le cas de chaque rattachement sous la clé cas', function (): void {
    rattacher($this, 7, 175.0);
    rattacher($this, 6, 100.0);

    $cas = $this->service->provisionsExercice(2025)->pluck('cas')->sort()->values()->all();

    expect($cas)->toBe(['cca', 'pca']);
});

it('signe l’impact sur le résultat de N selon le cas', function (int $classe, float $attendu): void {
    rattacher($this, $classe, 175.0);

    expect($this->service->provisionsExercice(2025)->first()['montant_signe'])->toBe($attendu);
})->with([
    'un PCA retire un produit : le résultat baisse' => [7, -175.0],
    'un CCA retire une charge : le résultat monte' => [6, 175.0],
]);

it('le total est la somme des impacts signés', function (): void {
    rattacher($this, 7, 175.0); // -175
    rattacher($this, 6, 100.0); // +100

    expect($this->service->totalProvisions(2025))->toBe(-75.0);
});

it('l’extourne de N+1 est l’image inverse du rattachement de N', function (): void {
    rattacher($this, 7, 175.0);
    rattacher($this, 6, 100.0);

    $extournes = $this->service->extournesExercice(2026)->keyBy('cas');

    expect($extournes['pca']['montant_signe'])->toBe(175.0)
        ->and($extournes['cca']['montant_signe'])->toBe(-100.0)
        ->and($this->service->totalExtournes(2026))->toBe(75.0);
});

it('ne mélange pas les exercices', function (): void {
    rattacher($this, 7, 175.0);

    expect($this->service->provisionsExercice(2026))->toBeEmpty()
        ->and($this->service->totalProvisions(2026))->toBe(0.0)
        ->and($this->service->extournesExercice(2025))->toBeEmpty();
});

it('compte pour zéro un rattachement dont le compte a disparu, et le dit au journal', function (): void {
    Log::spy();

    $compte = Compte::factory()->depense()->create(['association_id' => $this->association->id]);
    $provision = Provision::create([
        'exercice' => 2025,
        'compte_id' => $compte->id,
        'montant' => 50.0,
        'saisi_par' => auth()->id(),
    ]);
    $compte->delete(); // suppression logique

    $ligneRecap = $this->service->provisionsExercice(2025)->first();

    expect($ligneRecap['montant_signe'])->toBe(0.0)
        ->and($ligneRecap['cas'])->toBe('')
        ->and($ligneRecap['compte_nom'])->toBe('Compte supprimé')
        ->and($this->service->totalProvisions(2025))->toBe(0.0);

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $contexte) => $contexte['provision_id'] === (int) $provision->id
            && $contexte['compte_id'] === (int) $compte->id)
        ->atLeast()->once();
});
