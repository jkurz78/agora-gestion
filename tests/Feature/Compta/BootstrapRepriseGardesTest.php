<?php

declare(strict_types=1);

use App\Enums\JournalComptable;
use App\Enums\StatutExercice;
use App\Enums\TypeTransaction;
use App\Exceptions\Compta\ANouveauInvalideException;
use App\Models\Compte;
use App\Models\CompteBancaire;
use App\Models\Exercice;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Services\Compta\ANouveau\ANouveauPreview;
use App\Services\Compta\ANouveau\BootstrapANouveauService;
use App\Services\Compta\Migrations\SystemeSeeder;
use App\Tenant\TenantContext;

beforeEach(function (): void {
    SystemeSeeder::seed();
    $this->associationRepriseGardes = TenantContext::current();
});

/** @return array{0: CompteBancaire, 1: Compte} */
function banqueGardeReprise(string $numero, string $solde, string $dateSoldeInitial): array
{
    $banque = CompteBancaire::factory()->create([
        'solde_initial' => $solde,
        'date_solde_initial' => $dateSoldeInitial,
    ]);
    $compte = Compte::create([
        'numero_pcg' => $numero,
        'intitule' => 'Banque '.$numero,
        'classe' => 5,
        'compte_bancaire_id' => $banque->id,
        'actif' => true,
        'est_systeme' => true,
        'pour_inscriptions' => false,
        'lettrable' => false,
    ]);

    return [$banque, $compte];
}

function ecritureGardeReprise(Compte $debit, Compte $credit, string $date): void
{
    $transaction = Transaction::create([
        'type' => TypeTransaction::Recette,
        'date' => $date,
        'libelle' => 'Mouvement garde reprise',
        'montant_total' => '25.00',
        'mode_paiement' => null,
        'equilibree' => true,
        'type_ecriture' => 'normale',
        'journal' => JournalComptable::Od,
    ]);
    TransactionLigne::create([
        'transaction_id' => $transaction->id, 'compte_id' => $debit->id,
        'debit' => '25.00', 'credit' => '0.00', 'montant' => '25.00',
    ]);
    TransactionLigne::create([
        'transaction_id' => $transaction->id, 'compte_id' => $credit->id,
        'debit' => '0.00', 'credit' => '25.00', 'montant' => '25.00',
    ]);
}

it('refuse le double comptage quand un solde daté dans l’exercice cible porte des mouvements entre l’ouverture et lui', function (): void {
    [, $banque] = banqueGardeReprise('512-G1', '100.00', '2026-03-15');
    $contrepartie = Compte::ofNumeroSysteme('102');
    ecritureGardeReprise($banque, $contrepartie, '2026-01-10');

    try {
        app(BootstrapANouveauService::class)->preview(2025);
        $this->fail('Une ANouveauInvalideException était attendue.');
    } catch (ANouveauInvalideException $exception) {
        expect($exception->getMessage())
            ->toContain('512-G1')
            ->toContain('15/03/2026')
            ->toContain('01/09/2025');
    }
});

it('reste possible pour une adoption en cours d’exercice sans mouvement avant la date de référence', function (): void {
    banqueGardeReprise('512-G2', '100.00', '2026-03-15');

    $preview = app(BootstrapANouveauService::class)->preview(2025);

    expect($preview)->toBeInstanceOf(ANouveauPreview::class);
});

it('ne déclenche pas le refus pour un mouvement daté exactement le jour de référence', function (): void {
    [, $banque] = banqueGardeReprise('512-G3', '100.00', '2026-03-15');
    $contrepartie = Compte::ofNumeroSysteme('102');
    ecritureGardeReprise($banque, $contrepartie, '2026-03-15');

    $preview = app(BootstrapANouveauService::class)->preview(2025, ['meme_jour' => 'exclus']);

    expect($preview)->toBeInstanceOf(ANouveauPreview::class);
});

it('déduit l’exercice 2024 quand le solde le plus récent est daté du 31 août 2024', function (): void {
    Exercice::create(['annee' => 2024, 'statut' => StatutExercice::Ouvert]);
    Exercice::create(['annee' => 2025, 'statut' => StatutExercice::Ouvert]);
    banqueGardeReprise('512-G4', '100.00', '2024-08-31');

    expect(app(BootstrapANouveauService::class)->exerciceSuggere())->toBe(2024);
});

it('déduit l’exercice 2025 quand le solde le plus récent est daté du 31 août 2025', function (): void {
    Exercice::create(['annee' => 2024, 'statut' => StatutExercice::Ouvert]);
    Exercice::create(['annee' => 2025, 'statut' => StatutExercice::Ouvert]);
    banqueGardeReprise('512-G5', '100.00', '2025-08-31');

    expect(app(BootstrapANouveauService::class)->exerciceSuggere())->toBe(2025);
});

it('déduit l’exercice en cours quand l’association adopte l’outil en cours d’année', function (): void {
    // Cas révélé par le rejeu du site de démonstration : soldes datés du
    // 19 septembre pour un exercice ouvert le 1er. C'est le cas d'adoption le
    // plus fréquent, et non une exception — l'exercice à ouvrir est celui qui
    // contient la date, pas un hypothétique exercice commençant après elle.
    Exercice::create(['annee' => 2025, 'statut' => StatutExercice::Ouvert]);
    banqueGardeReprise('512-G7', '5000.00', '2025-09-19');

    expect(app(BootstrapANouveauService::class)->exerciceSuggere())->toBe(2025);
});

it('ne peut pas déduire d’exercice quand aucun solde bancaire n’est non nul', function (): void {
    Exercice::create(['annee' => 2025, 'statut' => StatutExercice::Ouvert]);
    banqueGardeReprise('512-G6', '0.00', '2025-08-31');

    expect(app(BootstrapANouveauService::class)->exerciceSuggere())->toBeNull();
});

it('la commande refuse plutôt que de deviner quand l’exercice ne peut pas être déduit', function (): void {
    $this->artisan('compta:bootstrap-an', [
        '--association' => $this->associationRepriseGardes->id,
        '--dry-run' => true,
    ])->expectsOutputToContain('--exercice')
        ->assertExitCode(1);
});
