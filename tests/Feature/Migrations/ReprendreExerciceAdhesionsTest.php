<?php

declare(strict_types=1);

use App\Livewire\CommunicationTiers;
use App\Models\Adhesion;
use App\Models\Association;
use App\Models\Compte;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Models\User;
use App\Tenant\TenantContext;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/*
 * Reprise (spec 2026-10-02, § 6 et AC-9) : toute adhésion à exercice nul reçoit
 * l'exercice dérivé de sa date de début. La règle est déterministe ; une adhésion
 * sans date de début n'est JAMAIS devinée : elle est laissée telle quelle et comptée.
 *
 * La migration a déjà tourné (table vide) lors du migrate initial : on la rejoue
 * sur nos données.
 */

function repriseExerciceAdhesionsMigration(): object
{
    return require database_path('migrations/2026_10_03_100001_reprendre_exercice_des_adhesions.php');
}

/** Insère une adhésion en SQL brut, exactement comme elle existe en production avant le correctif. */
function adhesionHistorique(int $associationId, int $tiersId, ?int $exercice, ?string $debut, ?string $fin, array $extra = []): int
{
    return (int) DB::table('adhesions')->insertGetId(array_merge([
        'association_id' => $associationId,
        'tiers_id' => $tiersId,
        'exercice' => $exercice,
        'date_debut' => $debut,
        'date_fin' => $fin,
        'mode' => 'duree',
        'created_at' => now(),
        'updated_at' => now(),
    ], $extra));
}

it('AC-9 · renseigne les 4 lignes de production, laisse intactes celles sans date de début, et rend les deux nombres', function (): void {
    $asso = TenantContext::currentId();

    // Les quatre lignes mesurées en production le 2026-10-02.
    $kohl = adhesionHistorique($asso, Tiers::factory()->create()->id, null, '2026-09-01', '2027-08-31');
    $salin = adhesionHistorique($asso, Tiers::factory()->create()->id, null, '2026-09-01', '2027-08-31');
    $moniotte = adhesionHistorique($asso, Tiers::factory()->create()->id, null, '2026-09-01', '2027-08-31');
    // Notz porte aussi la « Adhésion legacy » de la même transaction : exercice 2025, supprimée
    // logiquement le 21/06/2026. Avec l'ancienne clé unique, elle bloquait la reprise (D7/D8).
    $tiersNotz = Tiers::factory()->create();
    $notz = adhesionHistorique($asso, $tiersNotz->id, null, '2025-09-01', '2026-08-31');
    $legacyNotz = adhesionHistorique($asso, $tiersNotz->id, 2025, '2025-09-01', '2026-08-31', ['mode' => 'exercice', 'deleted_at' => '2026-06-21 03:59:51']);

    // Une adhésion sans date de début : impossible à reprendre, jamais devinée.
    $sansDate = adhesionHistorique($asso, Tiers::factory()->create()->id, null, null, null, ['mode' => 'exercice']);

    // Une adhésion déjà complète : on n'y touche pas, même si ses dates diraient autre chose.
    $dejaRenseignee = adhesionHistorique($asso, Tiers::factory()->create()->id, 2024, '2025-10-15', '2026-10-14');

    $bilan = repriseExerciceAdhesionsMigration()->reprendre();

    expect($bilan)->toBe(['reprises' => 4, 'laissees_intactes' => 1]);

    $exercice = fn (int $id): ?int => DB::table('adhesions')->where('id', $id)->value('exercice');
    expect($exercice($kohl))->toBe(2026)
        ->and($exercice($salin))->toBe(2026)
        ->and($exercice($moniotte))->toBe(2026)
        ->and($exercice($notz))->toBe(2025)
        // D8 : la ligne historique de Notz n'est pas touchée.
        ->and(DB::table('adhesions')->where('id', $legacyNotz)->value('deleted_at'))->not->toBeNull()
        ->and($exercice($legacyNotz))->toBe(2025)
        ->and($exercice($sansDate))->toBeNull()
        ->and($exercice($dejaRenseignee))->toBe(2024);

    // Les dates, elles, ne bougent pas : seul l'exercice est complété.
    $ligne = DB::table('adhesions')->where('id', $moniotte)->first();
    expect(substr((string) $ligne->date_debut, 0, 10))->toBe('2026-09-01')
        ->and(substr((string) $ligne->date_fin, 0, 10))->toBe('2027-08-31');
});

it('est rejouable : une seconde exécution ne reprend rien et compte toujours l\'adhésion sans date', function (): void {
    $asso = TenantContext::currentId();
    adhesionHistorique($asso, Tiers::factory()->create()->id, null, '2026-09-01', '2027-08-31');
    adhesionHistorique($asso, Tiers::factory()->create()->id, null, null, null);

    $migration = repriseExerciceAdhesionsMigration();

    expect($migration->reprendre())->toBe(['reprises' => 1, 'laissees_intactes' => 1])
        ->and($migration->reprendre())->toBe(['reprises' => 0, 'laissees_intactes' => 1]);
});

it('reprend aussi les adhésions annulées (soft-deleted), que la déduplication consulte', function (): void {
    $asso = TenantContext::currentId();
    $id = adhesionHistorique($asso, Tiers::factory()->create()->id, null, '2026-09-01', '2027-08-31', ['deleted_at' => now()]);

    $bilan = repriseExerciceAdhesionsMigration()->reprendre();

    expect($bilan['reprises'])->toBe(1)
        ->and(DB::table('adhesions')->where('id', $id)->value('exercice'))->toBe(2026);
});

it('respecte le mois de début d\'exercice de chaque association', function (): void {
    $calendaire = Association::factory()->create(['exercice_mois_debut' => 1]);
    $septembre = TenantContext::currentId();

    // 15 mars 2026 : exercice 2026 pour une association calendaire, 2025 pour une association de septembre.
    $idCalendaire = adhesionHistorique($calendaire->id, Tiers::factory()->create(['association_id' => $calendaire->id])->id, null, '2026-03-15', '2027-03-14');
    $idSeptembre = adhesionHistorique($septembre, Tiers::factory()->create()->id, null, '2026-03-15', '2027-03-14');

    repriseExerciceAdhesionsMigration()->reprendre();

    expect(DB::table('adhesions')->where('id', $idCalendaire)->value('exercice'))->toBe(2026)
        ->and(DB::table('adhesions')->where('id', $idSeptembre)->value('exercice'))->toBe(2025);
});

it('traite toutes les associations quand artisan migrate tourne sans TenantContext', function (): void {
    // En production, `artisan migrate` s'exécute hors requête : le scope tenant
    // fail-closed (`WHERE 1 = 0`) ferait de toute requête Eloquent un no-op silencieux.
    $autre = Association::factory()->create();
    $idCourante = adhesionHistorique(TenantContext::currentId(), Tiers::factory()->create()->id, null, '2026-09-01', '2027-08-31');
    $idAutre = adhesionHistorique($autre->id, Tiers::factory()->create(['association_id' => $autre->id])->id, null, '2026-09-01', '2027-08-31');

    TenantContext::clear();

    $bilan = repriseExerciceAdhesionsMigration()->reprendre();

    expect($bilan['reprises'])->toBe(2)
        ->and(DB::table('adhesions')->where('id', $idCourante)->value('exercice'))->toBe(2026)
        ->and(DB::table('adhesions')->where('id', $idAutre)->value('exercice'))->toBe(2026);
});

it('up() exécute la reprise', function (): void {
    $id = adhesionHistorique(TenantContext::currentId(), Tiers::factory()->create()->id, null, '2026-09-01', '2027-08-31');

    repriseExerciceAdhesionsMigration()->up();

    expect(DB::table('adhesions')->where('id', $id)->value('exercice'))->toBe(2026);
});

it('AC-10 · la reprise ne déplace pas le filtre de communication « Adhérents / Exercice en cours »', function (): void {
    // Le filtre lit les TRANSACTIONS, jamais la table adhesions : compléter
    // adhesions.exercice ne doit pas changer la liste d'une convocation d'AG.
    $association = Association::factory()->create(['email_from' => 'test@asso.fr']);
    $admin = User::factory()->create();
    $admin->associations()->attach($association->id, ['role' => 'admin', 'joined_at' => now()]);
    TenantContext::boot($association);
    session(['current_association_id' => $association->id, 'exercice_actif' => 2025]);

    $cotisation = Compte::factory()->pourCotisations()->create(['association_id' => $association->id]);
    $membre = Tiers::factory()->create(['association_id' => $association->id, 'nom' => 'Membre']);
    $tx = Transaction::factory()->create(['association_id' => $association->id, 'tiers_id' => $membre->id, 'type' => 'recette', 'date' => '2025-10-15']);
    TransactionLigne::withoutEvents(fn () => TransactionLigne::factory()->create(['transaction_id' => $tx->id, 'compte_id' => $cotisation->id, 'montant' => 30, 'credit' => 30]));
    adhesionHistorique($association->id, $membre->id, null, '2025-09-01', '2026-08-31', ['transaction_id' => $tx->id]);

    // Adhérent sans règlement : son adhésion à exercice nul ne le fait pas entrer dans le filtre.
    $offert = Tiers::factory()->create(['association_id' => $association->id, 'nom' => 'Offert']);
    adhesionHistorique($association->id, $offert->id, null, '2025-09-01', '2026-08-31');

    $noms = fn (): array => Livewire::actingAs($admin)
        ->test(CommunicationTiers::class)
        ->set('filtreAdherents', 'exercice')
        ->viewData('tiersList')
        ->pluck('nom')
        ->all();

    $avant = $noms();
    repriseExerciceAdhesionsMigration()->reprendre();
    $apres = $noms();

    expect(Adhesion::whereNull('exercice')->count())->toBe(0)
        ->and($avant)->toBe($apres)
        ->and($apres)->toHaveCount(1);
});
