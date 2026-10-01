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

use App\Models\Association;
use App\Models\Provision;
use App\Models\User;
use App\Services\Compta\EcritureGenerator;
use App\Services\Compta\Migrations\SystemeSeeder;
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

/**
 * Rattachement de l'exercice $exercice d'une charge de 500 € déjà comptabilisée
 * (CCA : 486 D / 6xx C), dans le tenant courant.
 *
 * La pièce d'origine est datée du 2026-04-08 : elle tombe dans l'exercice 2026 d'une
 * association en exercice civil, dans l'exercice 2025 d'une association en septembre.
 */
function rattachementDeChargeAvance(int $exercice): Provision
{
    SystemeSeeder::seed();
    $ligne = test()->ligneOrigine(6, 500.0);

    return Provision::create([
        'association_id' => TenantContext::currentId(),
        'exercice' => $exercice,
        'transaction_ligne_id' => $ligne->id,
        'montant' => 500.0,
        'saisi_par' => auth()->id(),
    ]);
}

it('l extourne d un rattachement est datee du premier jour de l exercice suivant', function (): void {
    tenantAvecMoisDebut(1);
    $provision = rattachementDeChargeAvance(2026);

    $transaction = app(EcritureGenerator::class)->pourProvisionExtourne($provision);

    // Exercice civil : l'exercice 2027 commence le 1er janvier. L'ancien code
    // écrivait le 1er septembre — une écriture au mauvais mois, dont le numéro
    // de pièce héritait ensuite du mauvais exercice.
    expect($transaction->date->toDateString())->toBe('2027-01-01')
        ->and($transaction->numero_piece)->toStartWith('2027:');
});

it('la dotation d un rattachement est datee du dernier jour de l exercice', function (): void {
    tenantAvecMoisDebut(1);
    $provision = rattachementDeChargeAvance(2026);

    $transaction = app(EcritureGenerator::class)->pourProvisionDotation($provision);

    // Exercice civil : l'exercice 2026 se clôt le 31 décembre, pas le 31 août.
    expect($transaction->date->toDateString())->toBe('2026-12-31')
        ->and($transaction->numero_piece)->toStartWith('2026:');
});

it('les ecritures de rattachement ne bougent pas pour une association en septembre', function (): void {
    tenantAvecMoisDebut(9);
    $provision = rattachementDeChargeAvance(2025);

    $generateur = app(EcritureGenerator::class);
    $dotation = $generateur->pourProvisionDotation($provision);
    $extourne = $generateur->pourProvisionExtourne($provision);

    // Le comportement historique, inchangé : le 1er septembre n'est plus codé en
    // dur, mais c'est ce que le réglage donne pour une association en septembre.
    expect($dotation->date->toDateString())->toBe('2026-08-31')
        ->and($dotation->numero_piece)->toStartWith('2025-2026:')
        ->and($extourne->date->toDateString())->toBe('2026-09-01')
        ->and($extourne->numero_piece)->toStartWith('2026-2027:');
});
