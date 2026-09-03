<?php

declare(strict_types=1);

/*
 * Le mois de début d'exercice est un RÉGLAGE de l'association
 * (`exercice_mois_debut`), pas une constante. Quatre endroits codaient
 * septembre en dur — dont un qui écrit une écriture comptable — et restaient
 * invisibles tant qu'aucune association n'était en exercice civil.
 *
 * Ces tests montent délibérément un tenant en exercice civil (janvier), le
 * seul cas où l'ancien code se trompait. Le tenant de septembre est vérifié en
 * regard pour prouver que la correction ne déplace rien pour lui.
 */

use App\Enums\TypeTransaction;
use App\Models\Association;
use App\Models\Compte;
use App\Models\Provision;
use App\Models\User;
use App\Services\Compta\EcritureGenerator;
use App\Services\NumeroPieceService;
use App\Services\RapportService;
use App\Tenant\TenantContext;
use Carbon\Carbon;

/** Monte un tenant avec le mois de début d'exercice demandé et s'y authentifie. */
function tenantAvecMoisDebut(int $moisDebut): Association
{
    $association = Association::factory()->create(['exercice_mois_debut' => $moisDebut]);
    $user = User::factory()->create();
    $user->associations()->attach($association->id, ['role' => 'admin', 'joined_at' => now()]);
    TenantContext::boot($association);
    session(['current_association_id' => $association->id]);
    test()->actingAs($user);

    return $association;
}

afterEach(function (): void {
    TenantContext::clear();
    session()->forget(['exercice_actif', 'current_association_id']);
});

it('le numero de piece rattache une date a l exercice du reglage, pas a septembre', function (): void {
    tenantAvecMoisDebut(1);

    // Exercice civil : octobre 2026 appartient à l'exercice 2026, qui s'écrit
    // « 2026 » tout court. L'ancien code rendait « 2026-2027 ».
    expect(app(NumeroPieceService::class)->exerciceFromDate(Carbon::parse('2026-10-15')))
        ->toBe('2026')
        ->and(app(NumeroPieceService::class)->exerciceFromDate(Carbon::parse('2026-02-03')))
        ->toBe('2026');
});

it('le numero de piece ne bouge pas pour une association en septembre', function (): void {
    tenantAvecMoisDebut(9);

    // Le comportement historique, inchangé : c'est ce qui rend la correction
    // sûre à poser sur la production existante.
    expect(app(NumeroPieceService::class)->exerciceFromDate(Carbon::parse('2026-10-15')))
        ->toBe('2026-2027')
        ->and(app(NumeroPieceService::class)->exerciceFromDate(Carbon::parse('2026-02-03')))
        ->toBe('2025-2026');
});

it('le flux de tresorerie ordonne ses douze mois depuis le mois de debut d exercice', function (): void {
    tenantAvecMoisDebut(1);

    $mensuel = app(RapportService::class)->fluxTresorerie(2026)['mensuel'];

    expect($mensuel)->toHaveCount(12)
        ->and($mensuel[0]['mois'])->toBe('Janvier 2026')
        ->and($mensuel[11]['mois'])->toBe('Décembre 2026');
});

it('le flux de tresorerie garde son ordre de septembre pour une association en septembre', function (): void {
    tenantAvecMoisDebut(9);

    $mensuel = app(RapportService::class)->fluxTresorerie(2025)['mensuel'];

    expect($mensuel[0]['mois'])->toBe('Septembre 2025')
        ->and($mensuel[11]['mois'])->toBe('Août 2026');
});

it('l extourne d une provision est datee du premier jour de l exercice suivant', function (): void {
    $association = tenantAvecMoisDebut(1);

    // 486 / 781 : le couple d'extourne d'une provision de dépense.
    foreach (['486', '781'] as $numero) {
        Compte::factory()->numero($numero)->create(['association_id' => $association->id]);
    }
    $compte = Compte::factory()->numero('606')->create(['association_id' => $association->id]);

    $provision = Provision::factory()->create([
        'association_id' => $association->id,
        'exercice' => 2026,
        'type' => TypeTransaction::Depense,
        'compte_id' => $compte->id,
        'montant' => 500.00,
    ]);

    $transaction = app(EcritureGenerator::class)->pourProvisionExtourne($provision);

    // Exercice civil : l'exercice 2027 commence le 1er janvier. L'ancien code
    // écrivait le 1er septembre — une écriture au mauvais mois, dont le numéro
    // de pièce héritait ensuite du mauvais exercice.
    expect($transaction->date->toDateString())->toBe('2027-01-01');
});
