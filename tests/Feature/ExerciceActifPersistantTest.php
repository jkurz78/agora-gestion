<?php

declare(strict_types=1);

use App\Enums\StatutExercice;
use App\Models\Association;
use App\Models\Compte;
use App\Models\Exercice;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Models\User;
use App\Services\ExerciceService;
use App\Tenant\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Écriture de classe 6 ou 7 datée, sans ventilation : c'est tout ce que
 * exercicePorteUneEcriture() regarde. Mirroir de budgetServiceTestLigne()
 * dans tests/Unit/BudgetServiceTest.php.
 */
function exerciceActifTestEcriture(string $date, int $classe, User $saisiPar): void
{
    $compte = Compte::factory()->numero($classe === 6 ? '606' : '706')->create();

    $transaction = Transaction::factory()->asDepense()->create([
        'date' => $date,
        'saisi_par' => $saisiPar->id,
    ]);
    // La factory génère déjà des lignes sans compte (pas de ventilation par
    // défaut) : on les jette pour ne garder que la ligne qu'on maîtrise.
    $transaction->lignes()->forceDelete();

    TransactionLigne::factory()->create([
        'transaction_id' => $transaction->id,
        'compte_id' => $compte->id,
        'montant' => 100.0,
        'debit' => 100.0,
        'credit' => 0.0,
    ]);

    // TransactionFactory::definition() calcule 'date' par défaut via
    // ExerciceService::defaultDate() — donc via current() — même quand on la
    // surcharge : la fabrique évalue toute sa définition avant que l'override
    // ne s'applique. Cet appel prématuré peut mémoïser en session un résultat
    // calculé AVANT que cette écriture n'existe, et fausser toute lecture
    // ultérieure dans le test. On purge donc la clé après coup, pour que la
    // prochaine résolution reparte des données réellement en base.
    session()->forget('exercice_actif');
}

beforeEach(function () {
    $this->association = Association::factory()->create();
    $this->user = User::factory()->create();
    $this->user->associations()->attach($this->association->id, ['role' => 'admin', 'joined_at' => now()]);
    TenantContext::boot($this->association);
    session(['current_association_id' => $this->association->id]);
    $this->actingAs($this->user);
    $this->service = app(ExerciceService::class);
});

afterEach(function () {
    CarbonImmutable::setTestNow(null);
    TenantContext::clear();
});

it('le choix bascule survit a la perte de session, lu depuis le pivot', function () {
    $exercice = Exercice::create(['annee' => 2024, 'statut' => StatutExercice::Ouvert]);
    $this->service->changerExerciceAffiche($exercice);

    // SESSION_LIFETIME expire : la clé disparaît, mais l'utilisateur reste
    // authentifié — c'est exactement le scénario du lendemain d'inactivité.
    session()->forget('exercice_actif');

    expect($this->service->current())->toBe(2024);
});

it('le choix est ecrit sur le pivot association_user, pas seulement en session', function () {
    $exercice = Exercice::create(['annee' => 2027, 'statut' => StatutExercice::Ouvert]);
    $this->service->changerExerciceAffiche($exercice);

    $valeur = DB::table('association_user')
        ->where('user_id', $this->user->id)
        ->where('association_id', $this->association->id)
        ->value('exercice_actif');

    expect((int) $valeur)->toBe(2027);
});

it('le choix memorise est propre a l association, pas global a l utilisateur', function () {
    $autreAssociation = Association::factory()->create();
    $this->user->associations()->attach($autreAssociation->id, ['role' => 'admin', 'joined_at' => now()]);

    $exerciceA = Exercice::create(['annee' => 2024, 'statut' => StatutExercice::Ouvert]);
    $this->service->changerExerciceAffiche($exerciceA);
    session()->forget('exercice_actif');

    TenantContext::boot($autreAssociation);
    $exerciceB = Exercice::create(['annee' => 2026, 'statut' => StatutExercice::Ouvert]);
    $this->service->changerExerciceAffiche($exerciceB);
    session()->forget('exercice_actif');

    // Même utilisateur, même processus : chaque association rend SON exercice
    // mémorisé, pas celui de la dernière bascule toutes associations confondues.
    // On reforget la session entre les deux lectures : current() la repeuple
    // dès qu'il résout depuis le pivot (mémoïsation par requête), et la clé de
    // session elle-même n'est pas scoping par tenant — seul le pivot l'est.
    TenantContext::boot($this->association);
    expect($this->service->current())->toBe(2024);
    session()->forget('exercice_actif');

    TenantContext::boot($autreAssociation);
    expect($this->service->current())->toBe(2026);
});

it('le defaut minimal decale d un an quand la nouvelle annee est vide', function () {
    // Lendemain de bascule : 2 septembre 2026, moisDebut = 9 → calcul brut = 2026.
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-02'));

    // Une écriture de classe 6 dans l'exercice précédent (2025 : sept 2025 → août 2026) uniquement.
    exerciceActifTestEcriture('2026-03-15', 6, $this->user);

    // Aucune préférence mémorisée : pas d'appel à changerExerciceAffiche().
    expect($this->service->current())->toBe(2025);
});

it('le defaut ne decale pas des qu il y a une ecriture dans l annee courante', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-02'));

    // Même montage que le cas précédent (écriture dans l'exercice précédent)…
    exerciceActifTestEcriture('2026-03-15', 6, $this->user);
    // … plus une écriture dans l'exercice courant (2026 : sept 2026 → août 2027).
    exerciceActifTestEcriture('2026-09-02', 7, $this->user);

    expect($this->service->current())->toBe(2026);
});

it('le defaut ne decale pas quand les deux annees sont vides', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-02'));

    // Aucune écriture nulle part : le calcul brut reste inchangé.
    expect($this->service->current())->toBe(2026);
});

it('aucun tenant boote : current ne leve rien et rend le calcul de la date', function () {
    TenantContext::clear();
    session()->forget('exercice_actif');

    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-02'));

    // Sans tenant, moisDebut() retombe sur 9 par défaut : 2 septembre → 2026.
    expect(fn () => $this->service->current())->not->toThrow(Throwable::class);
    expect($this->service->current())->toBe(2026);
});
