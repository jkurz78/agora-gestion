<?php

declare(strict_types=1);

use App\Enums\JournalComptable;
use App\Enums\OrigineANouveau;
use App\Enums\TypeTransaction;
use App\Models\ANouveauGeneration;
use App\Models\Compte;
use App\Models\CompteBancaire;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Models\User;
use App\Services\Compta\Migrations\SystemeSeeder;
use App\Tenant\TenantContext;

beforeEach(function (): void {
    config(['compta.use_partie_double' => true]);
    SystemeSeeder::seed();
    $this->associationBootstrap = TenantContext::current();

    $this->acteurBootstrap = User::factory()->create(['email' => 'bootstrap-an@example.test']);
    $this->acteurBootstrap->associations()->attach($this->associationBootstrap->id, [
        'role' => 'admin',
        'joined_at' => now(),
    ]);
});

function banqueBootstrapAN(string $numero, string $solde): array
{
    $banque = CompteBancaire::factory()->create([
        'solde_initial' => $solde,
        'date_solde_initial' => '2025-08-31',
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

function ecritureBootstrapAN(Compte $debit, Compte $credit, string $date, ?int $tiersCredit = null): void
{
    $transaction = Transaction::create([
        'type' => TypeTransaction::Recette,
        'date' => $date,
        'libelle' => 'Écriture reprise AN',
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
        'tiers_id' => $tiersCredit,
    ]);
}

it('exige une association explicite', function (): void {
    $this->artisan('compta:bootstrap-an', ['--dry-run' => true])
        ->expectsOutputToContain('--association')
        ->assertExitCode(1);
});

it('fait un dry-run sans aucune ecriture', function (): void {
    banqueBootstrapAN('512-B1', '100.00');
    banqueBootstrapAN('512-B2', '-20.00');

    $this->artisan('compta:bootstrap-an', [
        '--association' => $this->associationBootstrap->id,
        '--exercice' => 2025,
        '--dry-run' => true,
    ])->expectsOutputToContain('DRY-RUN')
        ->expectsOutputToContain('512-B1')
        ->assertExitCode(0);

    expect(ANouveauGeneration::count())->toBe(0);
});

it('refuse la confirmation sans acteur appartenant au tenant', function (): void {
    $intrus = User::factory()->create(['email' => 'intrus-an@example.test']);

    $this->artisan('compta:bootstrap-an', [
        '--association' => $this->associationBootstrap->id,
        '--acteur' => $intrus->email,
        '--exercice' => 2025,
        '--confirmer' => true,
    ])->expectsOutputToContain('n’appartient pas')
        ->assertExitCode(1);
});

it('exige un arbitrage si un mouvement existe le jour du solde initial', function (): void {
    [, $banque] = banqueBootstrapAN('512-A', '100.00');
    $contrepartie = Compte::ofNumeroSysteme('102');
    ecritureBootstrapAN($banque, $contrepartie, '2025-08-31');

    $this->artisan('compta:bootstrap-an', [
        '--association' => $this->associationBootstrap->id,
        '--exercice' => 2025,
        '--dry-run' => true,
    ])->expectsOutputToContain('--meme-jour=inclus|exclus')
        ->assertExitCode(1);
});

it('cree une reprise equilibree avec 512 102 et les postes 401 ouverts', function (): void {
    [, $banque1] = banqueBootstrapAN('512-C1', '100.00');
    banqueBootstrapAN('512-C2', '-20.00');
    $tiers = Tiers::factory()->create();
    ecritureBootstrapAN(
        $banque1,
        Compte::ofNumeroSysteme('401'),
        '2025-08-30',
        (int) $tiers->id,
    );

    $this->artisan('compta:bootstrap-an', [
        '--association' => $this->associationBootstrap->id,
        '--acteur' => $this->acteurBootstrap->email,
        '--exercice' => 2025,
        '--confirmer' => true,
    ])->expectsOutputToContain('Reprise initiale créée')
        ->assertExitCode(0);

    $generation = ANouveauGeneration::activePourCible(2025);
    $numeros = $generation?->transaction?->lignes()->with('compte')->get()->pluck('compte.numero_pcg');

    expect($generation?->origine)->toBe(OrigineANouveau::RepriseInitiale)
        ->and($generation?->transaction?->equilibree)->toBeTrue()
        ->and($numeros)->toContain('512-C1', '512-C2', '401', '102');
});

it('refuse un second run confirme', function (): void {
    banqueBootstrapAN('512-D', '10.00');
    $options = [
        '--association' => $this->associationBootstrap->id,
        '--acteur' => $this->acteurBootstrap->email,
        '--exercice' => 2025,
        '--confirmer' => true,
    ];

    $this->artisan('compta:bootstrap-an', $options)->assertExitCode(0);
    $this->artisan('compta:bootstrap-an', $options)
        ->expectsOutputToContain('existe déjà')
        ->assertExitCode(1);
});
