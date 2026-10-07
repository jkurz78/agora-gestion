<?php

declare(strict_types=1);

use App\Enums\CasProvision;
use App\Enums\StatutExercice;
use App\Livewire\Exercices\ClotureWizard;
use App\Models\Compte;
use App\Models\Exercice;
use App\Models\Provision;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Compta\Migrations\SystemeSeeder;
use App\Services\Compta\RattachementExerciceService;
use App\Services\ProvisionService;
use App\Tenant\TenantContext;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| AC-14 : le récapitulatif du wizard de clôture, après un vrai rattachement
|--------------------------------------------------------------------------
|
| Un rattachement adossé (CCA/PCA) ne porte pas de `compte_id` : son compte est celui de la
| ligne d'origine. Le récapitulatif de l'étape 2 doit l'y lire, et ne jamais afficher « Compte
| supprimé » pour un compte qui existe.
|
| Les rattachements sont fabriqués par RattachementExerciceService::rattacher(), pas posés à la
| main : ce sont les mêmes lignes, et les mêmes écritures, que celles d'un utilisateur qui a
| cliqué sur l'écran. Le helper provisionPourLigne() de tests/Helpers/Rattachement.php n'en
| génère aucune, ce qui laisserait le récapitulatif et le grand livre se contredire sans que
| rien ne le montre.
*/

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->user->associations()->attach(TenantContext::currentId(), ['role' => 'admin', 'joined_at' => now()]);
    $this->actingAs($this->user);

    Exercice::create(['association_id' => TenantContext::currentId(), 'annee' => 2025, 'statut' => StatutExercice::Ouvert]);
    session(['exercice_actif' => 2025]);

    // 486 et 487 : les comptes de régularisation, posés par le seeder système.
    SystemeSeeder::seed();
});

/**
 * Rattache à l'exercice 2025, par le service, une ligne d'origine de la classe donnée.
 */
function recapRattacher(object $test, int $classe, float $montant): Provision
{
    $ligne = $test->ligneOrigine($classe, $montant);

    return app(RattachementExerciceService::class)->rattacher($ligne, null, auth()->user());
}

/**
 * Le nombre de lectures de la table `provisions` émises par $action. Les chargements liés
 * (lignes, comptes) lisent d'autres tables : ils ne comptent pas.
 */
function recapLecturesDeProvisions(Closure $action): int
{
    $lectures = 0;

    DB::listen(function ($requete) use (&$lectures): void {
        if (preg_match('/^\s*select\b.*\bfrom\s+[`"]provisions[`"]/is', $requete->sql) === 1) {
            $lectures++;
        }
    });

    $action();

    return $lectures;
}

// ── Le service : le compte vient de l'origine ────────────────────────────────

it('affiche le compte de la ligne d’origine, le cas et l’impact signé', function (int $classe, float $montant, CasProvision $cas, float $impact): void {
    $ligne = $this->ligneOrigine($classe, $montant);
    $provision = app(RattachementExerciceService::class)->rattacher($ligne, null, auth()->user());

    $recap = app(ProvisionService::class)->provisionsExercice(2025)->sole();

    expect($recap['id'])->toBe((int) $provision->id)
        ->and($recap['compte_id'])->toBe((int) $ligne->compte_id)
        ->and($recap['compte_nom'])->toBe($ligne->compte->intitule)
        ->and($recap['compte_numero'])->toBe($ligne->compte->numero_pcg)
        ->and($recap['compte_nom'])->not->toBe('Compte supprimé')
        ->and($recap['cas'])->toBe($cas->value)
        ->and($recap['cas_libelle'])->toBe($cas->label())
        ->and($recap['montant'])->toBe($montant)
        ->and($recap['montant_signe'])->toBe($impact);
})->with([
    'PCA : un produit de 175 € retiré de N, le résultat baisse' => [7, 175.0, CasProvision::ProduitConstateDavance, -175.0],
    'CCA : une charge de 90 € retirée de N, le résultat monte' => [6, 90.0, CasProvision::ChargeConstateeDavance, 90.0],
]);

it('annonce l’impact que l’écriture de rattachement a vraiment sur le résultat de N', function (int $classe, float $montant): void {
    $provision = recapRattacher($this, $classe, $montant);

    // La dotation est l'écriture normale du rattachement, au dernier jour de N ; l'autre est
    // l'extourne, dans N+1. impactSurLeResultat() lit les classes 6 et 7 de l'écriture.
    $dotation = Transaction::where('provision_id', $provision->id)->where('type_ecriture', 'normale')->sole();

    expect(app(ProvisionService::class)->provisionsExercice(2025)->sole()['montant_signe'])
        ->toBe(impactSurLeResultat($dotation->load('lignes.compte')));
})->with([
    'PCA' => [7, 175.0],
    'CCA' => [6, 90.0],
]);

// ── Le wizard rendu ──────────────────────────────────────────────────────────

it('rend, à l’étape 2, le cas en clair, le compte de l’origine, le libellé, le montant et l’impact', function (): void {
    $pca = $this->ligneOrigine(7, 175.0);
    $pca->update(['libelle' => 'Adhésions 2026-2027']);
    app(RattachementExerciceService::class)->rattacher($pca, null, auth()->user());

    $cca = $this->ligneOrigine(6, 90.0);
    $cca->update(['libelle' => 'Assurance 2026-2027']);
    app(RattachementExerciceService::class)->rattacher($cca, null, auth()->user());

    $composant = Livewire::test(ClotureWizard::class)
        ->set('step', 2)
        ->assertSee('Écritures de rattachement')
        // Le cas, en clair : l'étiquette de l'enum, pas sa valeur brute.
        ->assertSee(CasProvision::ProduitConstateDavance->label())
        ->assertSee(CasProvision::ChargeConstateeDavance->label())
        // Le compte de l'origine : numéro puis intitulé, jamais « Compte supprimé ».
        ->assertSee($pca->compte->numero_pcg.' — '.$pca->compte->intitule)
        ->assertSee($cca->compte->numero_pcg.' — '.$cca->compte->intitule)
        ->assertDontSee('Compte supprimé')
        // Le libellé, le montant, et l'impact signé sur le résultat.
        ->assertSee('Adhésions 2026-2027')
        ->assertSee('Assurance 2026-2027')
        ->assertSee('175,00')
        ->assertSee('90,00')
        ->assertSee('-175,00')
        ->assertSee('+90,00');

    // Ni « pca » ni « cca » tels quels, ni l'ancien vocabulaire.
    expect($composant->html())->not->toMatch('/\b(pca|cca)\b/i')
        ->and($composant->html())->not->toContain('Provisions de fin d');
});

it('ne charge les rattachements qu’une fois pour l’étape 2', function (): void {
    recapRattacher($this, 7, 175.0);
    recapRattacher($this, 6, 90.0);

    $composant = Livewire::test(ClotureWizard::class);

    $lectures = recapLecturesDeProvisions(fn () => $composant->set('step', 2));

    expect($lectures)->toBe(1);
});

it('garde le même total : la somme des impacts signés, affichée en pied de tableau', function (): void {
    recapRattacher($this, 7, 175.0); // -175
    recapRattacher($this, 6, 90.0);  // +90

    $composant = Livewire::test(ClotureWizard::class)->set('step', 2);
    $summary = $composant->viewData('summary');

    expect($summary['totalProvisions'])->toBe(-85.0)
        ->and($summary['nbProvisions'])->toBe(2)
        ->and($summary['totalProvisions'])->toBe(round((float) $summary['provisions']->sum('montant_signe'), 2));

    $composant->assertSee('-85,00');
});

it('annonce un impact net déjà compris dans le résultat : le résultat du wizard passe de 175 à 0 pour une PCA, de −90 à 0 pour une CCA', function (int $classe, float $montant, float $avant): void {
    // Le résultat de l'exercice que lit le wizard (CompteResultatBuilder::totauxResultat) avant le rattachement :
    // le produit de 175 € (ou la charge de 90 €) pèse sur 2025-2026.
    $ligne = $this->ligneOrigine($classe, $montant);
    $lire = fn (): array => Livewire::test(ClotureWizard::class)->set('step', 2)->viewData('summary');

    expect($lire()['resultat'])->toBe($avant);
    expect($lire()['nbProvisions'])->toBe(0);

    app(RattachementExerciceService::class)->rattacher($ligne, null, auth()->user());

    // Après : la dotation, datée du dernier jour de N, a sorti le montant du résultat.
    $apres = $lire();

    expect($apres['resultat'])->toBe(0.0);
    expect($apres['nbProvisions'])->toBe(1);
    // « Impact net sur le résultat (déjà compris dans le résultat ci-dessus) » : l'affirmation de l'étape 2.
    // Le résultat d'après est celui d'avant PLUS l'impact net affiché, et rien d'autre : l'ajouter une seconde
    // fois au résultat le compterait deux fois.
    expect($apres['totalProvisions'])->toBe(round(-$avant, 2));
    expect($apres['resultat'])->toBe(round($avant + $apres['totalProvisions'], 2));

    Livewire::test(ClotureWizard::class)->set('step', 2)
        ->assertSee('Impact net sur le résultat')
        ->assertSee('(déjà compris dans le résultat ci-dessus)')
        ->assertSee('Résultat : EXCÉDENT');
})->with([
    'PCA : un produit de 175 € retiré de N, le résultat passe de 175 à 0' => [7, 175.0, 175.0],
    'CCA : une charge de 90 € retirée de N, le résultat passe de −90 à 0' => [6, 90.0, -90.0],
])->skip(fn (): bool => DB::getDriverName() === 'sqlite', RATTACHEMENT_SQLITE_SKIP_RAISON);

it('n’affiche « Compte supprimé » que pour un compte réellement supprimé', function (): void {
    $compte = Compte::factory()->depense()->create(['association_id' => TenantContext::currentId()]);
    Provision::create([
        'exercice' => 2025,
        'compte_id' => $compte->id,
        'libelle' => 'Facture non parvenue',
        'montant' => 50.0,
        'saisi_par' => auth()->id(),
    ]);
    $compte->delete(); // suppression logique

    // La ligne reste lisible, sans cas ni compte : le récapitulatif ne casse pas.
    Livewire::test(ClotureWizard::class)
        ->set('step', 2)
        ->assertSee('Facture non parvenue')
        ->assertSee('Compte supprimé');
});
