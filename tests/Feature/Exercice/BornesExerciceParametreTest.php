<?php

declare(strict_types=1);

use App\Models\Association;
use App\Models\Compte;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Models\User;
use App\Services\BudgetService;
use App\Services\ExerciceService;
use App\Services\Rapports\CompteResultatBuilder;
use App\Services\Rapports\FluxTresorerieBuilder;
use App\Tenant\TenantContext;

/**
 * Les bornes d'un exercice appartiennent à l'association, pas au code.
 *
 * `association.exercice_mois_debut` fixe le mois de début, et
 * `ExerciceService::dateRange()` en dérive les bornes — y compris le cas
 * calendaire (janvier-décembre), où l'exercice tient dans une seule année
 * civile. Plusieurs services et écrans recalculaient pourtant
 * « septembre-août » en dur, ce qui rendait leurs chiffres faux pour toute
 * association qui ne suit pas ce calendrier.
 *
 * Trois paramétrages sont couverts :
 *
 *  - **9 — septembre-août** : le cas historique, qui ne doit rien changer ;
 *  - **1 — janvier-décembre** : l'exercice civil, où le décalage d'année
 *    disparaît. C'est le cas qui casse un calcul écrit comme `annee + 1` ;
 *  - **3 — mars-février** : hémisphère sud. Il traverse deux années civiles
 *    sans coïncider avec septembre, donc il attrape les erreurs de bornes que
 *    le cas calendaire laisse passer.
 */
$parametrages = [
    'septembre-août' => [9, 2025, '2025-09-01', '2026-08-31', '2025-08-31', '2026-01-15'],
    'janvier-décembre' => [1, 2025, '2025-01-01', '2025-12-31', '2024-12-31', '2025-06-15'],
    'mars-février' => [3, 2025, '2025-03-01', '2026-02-28', '2025-02-28', '2025-11-20'],
];

function contexteExercice(int $moisDebut): Association
{
    $association = Association::factory()->create(['exercice_mois_debut' => $moisDebut]);
    $user = User::factory()->create();
    $user->associations()->attach($association->id, ['role' => 'admin', 'joined_at' => now()]);

    TenantContext::boot($association);
    session(['current_association_id' => $association->id]);
    test()->actingAs($user);

    return $association;
}

/** Une écriture de charge à la date donnée, sur le compte fourni. */
function ecritureCharge(Association $association, Compte $compte, string $date, float $montant): void
{
    $tx = Transaction::factory()->create([
        'association_id' => (int) $association->id,
        'type' => 'depense',
        'date' => $date,
        'montant_total' => $montant,
    ]);

    TransactionLigne::factory()->create([
        'transaction_id' => $tx->id,
        'compte_id' => $compte->id,
        'debit' => $montant,
        'credit' => 0,
        'montant' => $montant,
    ]);
}

afterEach(function (): void {
    TenantContext::clear();
    session()->forget('exercice_actif');
});

it('dérive les bornes du mois de début de l’association', function (
    int $moisDebut, int $exercice, string $debutAttendu, string $finAttendue
): void {
    contexteExercice($moisDebut);

    $range = app(ExerciceService::class)->dateRange($exercice);

    expect($range['start']->toDateString())->toBe($debutAttendu)
        ->and($range['end']->toDateString())->toBe($finAttendue);
})->with(array_map(
    fn (array $p): array => [$p[0], $p[1], $p[2], $p[3]],
    $parametrages
));

it('borne le réalisé du budget sur l’exercice de l’association', function (
    int $moisDebut, int $exercice, string $debut, string $fin, string $veille, string $milieu
): void {
    $association = contexteExercice($moisDebut);

    $compte = Compte::factory()->create([
        'association_id' => (int) $association->id,
        'numero_pcg' => '606', 'classe' => 6,
    ]);

    // Premier jour de l'exercice et un jour au milieu : comptés.
    ecritureCharge($association, $compte, $debut, 100.00);
    ecritureCharge($association, $compte, $milieu, 50.00);
    // Veille du premier jour : hors exercice.
    ecritureCharge($association, $compte, $veille, 999.00);

    expect(app(BudgetService::class)->realise((int) $compte->id, $exercice))
        ->toBe(150.00, "Mois de début {$moisDebut} : le réalisé doit couvrir {$debut} → {$fin}.");
})->with($parametrages);

it('borne le compte de résultat sur l’exercice de l’association', function (
    int $moisDebut, int $exercice, string $debut, string $fin, string $veille, string $milieu
): void {
    $association = contexteExercice($moisDebut);

    $compte = Compte::factory()->create([
        'association_id' => (int) $association->id,
        'numero_pcg' => '606', 'classe' => 6,
    ]);

    ecritureCharge($association, $compte, $debut, 100.00);
    ecritureCharge($association, $compte, $milieu, 50.00);
    ecritureCharge($association, $compte, $veille, 999.00);

    $data = app(CompteResultatBuilder::class)->compteDeResultat($exercice);
    $charges = collect($data['charges'])->sum('montant_n');

    expect(round((float) $charges, 2))
        ->toBe(150.00, "Mois de début {$moisDebut} : le compte de résultat doit couvrir {$debut} → {$fin}.");
})->with($parametrages);

it('borne le flux de trésorerie sur l’exercice de l’association', function (
    int $moisDebut, int $exercice, string $debut, string $fin, string $veille, string $milieu
): void {
    $association = contexteExercice($moisDebut);

    $compte512 = Compte::factory()->create([
        'association_id' => (int) $association->id,
        'numero_pcg' => '5121', 'classe' => 5,
    ]);

    ecritureCharge($association, $compte512, $milieu, 80.00);
    ecritureCharge($association, $compte512, $veille, 999.00);

    $flux = app(FluxTresorerieBuilder::class)->fluxTresorerie($exercice);

    // Seule l'écriture du milieu tombe dans l'exercice : celle de la veille du
    // premier jour appartient à la période précédente, donc à l'ouverture.
    expect($flux['synthese']['variation'])
        ->toBe(80.00, "Mois de début {$moisDebut} : le flux doit couvrir {$debut} → {$fin}.");
})->with($parametrages);

it('libelle l’exercice selon le paramétrage', function (int $moisDebut, int $exercice): void {
    contexteExercice($moisDebut);

    // Calendaire : « 2025 ». Décalé : « 2025-2026 ».
    expect(app(ExerciceService::class)->label($exercice))
        ->toBe($moisDebut === 1 ? '2025' : '2025-2026');
})->with(array_map(fn (array $p): array => [$p[0], $p[1]], $parametrages));
