<?php

use App\Enums\OrigineANouveau;
use App\Enums\StatutANouveau;
use App\Models\ANouveauGeneration;
use App\Models\Association;
use App\Models\Compte;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Tenant\TenantContext;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Cache;
use Tests\Support\LigneDonHelper;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

/*
 * Global test bootstrap — S6/T3
 *
 * TenantScope is fail-closed : unbooted queries return zero rows (see
 * App\Tenant\TenantScope::apply). Most tests in this suite exercise
 * tenant-scoped models and therefore need a booted TenantContext to
 * return any data at all. This beforeEach creates a default Association
 * and boots it so those tests keep working.
 *
 * Security-sensitive tests that verify the fail-closed behavior itself
 * (e.g. tests/Unit/Tenant/TenantScopeTest.php, CrossTenantAccessTest
 * scenario 9) MUST start their own beforeEach with TenantContext::clear()
 * to undo this bootstrap. See AccessControlTest for the pattern.
 */
pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->use(LigneDonHelper::class)
    ->beforeEach(function () {
        // Freeze time to a stable mid-exercice date (exercice 2025 = Sept 2025 → Aug 2026).
        // This eliminates date-dependent test fragility: factory dates, ExerciceService::current(),
        // whereBetween boundaries all behave consistently regardless of when tests actually run.
        // Tests that need a specific date override this with their own setTestNow/travelTo.
        Carbon::setTestNow('2026-01-15 10:00:00');

        // Boot a default tenant context so that tenant-scoped models work out of the box.
        $association = Association::factory()->create();
        TenantContext::boot($association);

        // Prime the install gate cache so tests can hit /dashboard, /login, etc.
        Cache::put('app.installed', true);
    })
    ->afterEach(function () {
        TenantContext::clear();
        Carbon::setTestNow();
    })
    ->in('Feature', 'Livewire', 'Unit');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/**
 * Raccourci global pour récupérer un compte système (411, 401, 5112, 530, etc.)
 * dans le tenant courant. Utilise firstOrFail() pour échouer clairement si le
 * compte est absent (ex. SystemeSeeder non appelé dans le beforeEach).
 */
function compteSysteme(string $numero): Compte
{
    return Compte::where('numero_pcg', $numero)
        ->where('association_id', TenantContext::currentId())
        ->firstOrFail();
}

/**
 * Une reprise initiale réaliste : la génération et sa pièce d'à-nouveau, portant
 * une ligne sur chacun des comptes 512X passés en argument.
 *
 * Partagé (au lieu de vivre dans un seul fichier de test) car un test lancé
 * seul par son chemin de fichier ne charge que ce fichier — les fonctions
 * globales déclarées dans un autre fichier de test ne sont alors pas
 * disponibles, contrairement à une exécution de suite complète ou filtrée qui
 * charge tous les fichiers avant d'appliquer le filtre.
 *
 * @param  list<Compte>  $comptesCouverts
 */
function etatComptaCreerReprise(
    object $contexte,
    array $comptesCouverts,
    int $exerciceCible = 2024,
    StatutANouveau $statut = StatutANouveau::Active,
    OrigineANouveau $origine = OrigineANouveau::RepriseInitiale,
): ANouveauGeneration {
    $piece = Transaction::factory()->create([
        'association_id' => $contexte->association->id,
        'compte_id' => $contexte->compteBancaire->id,
        'equilibree' => true,
    ]);

    foreach ($comptesCouverts as $compte) {
        TransactionLigne::forceCreate([
            'transaction_id' => (int) $piece->id,
            'compte_id' => (int) $compte->id,
            'montant' => 0,
            'debit' => '2388.82',
            'credit' => 0,
        ]);
    }

    return ANouveauGeneration::create([
        'association_id' => $contexte->association->id,
        'exercice_source' => $exerciceCible - 1,
        'exercice_cible' => $exerciceCible,
        'transaction_id' => (int) $piece->id,
        'origine' => $origine,
        'statut' => $statut,
        'cree_par_id' => $contexte->user->id,
    ]);
}

/**
 * Asserts that a rendered Mailable contains no unsubstituted {xxx} placeholders
 * in either its subject or its HTML body.
 *
 * This is the canonical "leak test" for all Mailables in AgoraGestion.
 * Any {xxx} surviving after TemplateSubstitution::apply() is a bug visible to users.
 */
function assertNoUnsubstitutedEmailVariables(Mailable $mail): void
{
    $subject = $mail->envelope()->subject;
    $html = $mail->render();
    $combined = $subject."\n".$html;
    preg_match_all('/\{[a-z_]+\}/', $combined, $matches);
    expect($matches[0])->toBe(
        [],
        'Variables non substituées détectées dans l\'email : '.implode(', ', array_unique($matches[0]))
    );
}
