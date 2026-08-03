<?php

declare(strict_types=1);

use App\Models\Compte;
use App\Models\CompteBancaire;
use App\Services\Compta\Migrations\BancairesSeeder;
use App\Tenant\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
 * Les attributs bancaires (IBAN, BIC, domiciliation, solde initial et sa date)
 * sont saisis sur la fiche CompteBancaire, puis recopiés dans le compte 512X du
 * plan comptable par BancairesSeeder — en INSERT IGNORE, donc une seule fois, à
 * la création. Sans propagation, toute correction ultérieure sur la fiche reste
 * invisible du moteur comptable, qui ne lit que `comptes`.
 *
 * Constaté en recette le 2026-07-29 : date du solde initial corrigée au
 * 31/08/2024 sur la fiche bancaire, restée au 31/08/2025 sur le compte 5121 —
 * l'à-nouveau de clôture s'est appuyé sur la valeur périmée.
 */

beforeEach(function (): void {
    $this->compteBancaire = CompteBancaire::factory()->create([
        'association_id' => (int) TenantContext::currentId(),
        'iban' => 'FR7610278061060002096230183',
        'solde_initial' => 2388.82,
        'date_solde_initial' => '2025-08-31',
    ]);

    BancairesSeeder::seed();

    $this->compte512 = Compte::where('compte_bancaire_id', (int) $this->compteBancaire->id)->sole();
});

it('propage la date du solde initial corrigée vers le compte du plan comptable', function (): void {
    expect($this->compte512->date_solde_initial->toDateString())->toBe('2025-08-31');

    $this->compteBancaire->update(['date_solde_initial' => '2024-08-31']);

    expect($this->compte512->fresh()->date_solde_initial->toDateString())->toBe('2024-08-31');
});

it('propage le solde initial corrigé vers le compte du plan comptable', function (): void {
    $this->compteBancaire->update(['solde_initial' => 3000.50]);

    expect((float) $this->compte512->fresh()->solde_initial)->toBe(3000.50);
});

it('propage les coordonnées bancaires corrigées', function (): void {
    $this->compteBancaire->update([
        'iban' => 'FR7630006000019876543210456',
        'bic' => 'CMCIFR2A',
        'domiciliation' => 'Crédit Mutuel',
    ]);

    $compte = $this->compte512->fresh();

    expect($compte->iban)->toBe('FR7630006000019876543210456')
        ->and($compte->bic)->toBe('CMCIFR2A')
        ->and($compte->domiciliation)->toBe('Crédit Mutuel');
});

it('crée le compte du plan comptable dès la création de la fiche bancaire', function (): void {
    // Sans ce hook, un compte bancaire créé depuis Paramètres n'avait aucun 512X :
    // ses écritures partaient sans contrepartie de trésorerie, en silence.
    $livret = CompteBancaire::factory()->create([
        'association_id' => (int) TenantContext::currentId(),
        'nom' => 'Livret Épargne',
        'iban' => 'FR7630003000011234567890143',
        'solde_initial' => 24010.00,
        'date_solde_initial' => '2024-08-31',
    ]);

    $compte = Compte::where('compte_bancaire_id', (int) $livret->id)->sole();

    expect($compte->numero_pcg)->toBe('5122')
        ->and($compte->classe)->toBe(5)
        ->and($compte->intitule)->toBe('Livret Épargne')
        ->and($compte->iban)->toBe('FR7630003000011234567890143')
        ->and((float) $compte->solde_initial)->toBe(24010.00)
        ->and($compte->date_solde_initial->toDateString())->toBe('2024-08-31');
});

it('ne réutilise jamais le numéro d’un compte bancaire supprimé', function (): void {
    // La suppression d'une fiche bancaire annule compte_bancaire_id (nullOnDelete)
    // mais laisse le compte — et ses écritures — dans le plan comptable. Son
    // numéro reste pris à vie ; le rang recalculé de BancairesSeeder l'ignorait.
    $deuxieme = CompteBancaire::factory()->create([
        'association_id' => (int) TenantContext::currentId(),
        'iban' => 'FR7630003000011234567890143',
    ]);

    expect(Compte::where('compte_bancaire_id', (int) $deuxieme->id)->sole()->numero_pcg)->toBe('5122');

    $deuxieme->delete();

    $troisieme = CompteBancaire::factory()->create([
        'association_id' => (int) TenantContext::currentId(),
        'iban' => 'FR7610011000201234567890188',
    ]);

    expect(Compte::where('compte_bancaire_id', (int) $troisieme->id)->sole()->numero_pcg)->toBe('5123');
});

it('ne crée pas de second compte quand le seed rejoue sur un numéro renuméroté', function (): void {
    // Le numéro d'un compte sans écriture est modifiable (décision D3). Le seed,
    // idempotent sur le seul numéro calculé, ne reconnaissait plus le compte
    // renuméroté et lui en fabriquait un doublon à chaque rejeu.
    $this->compte512->update(['numero_pcg' => '5129']);

    BancairesSeeder::seed();

    expect(Compte::where('compte_bancaire_id', (int) $this->compteBancaire->id)->count())->toBe(1);
});

it('ne touche pas au compte quand aucun attribut bancaire ne change', function (): void {
    $avant = $this->compte512->fresh()->updated_at;

    $this->compteBancaire->update(['nom' => 'Compte Courant renommé']);

    expect($this->compte512->fresh()->updated_at->eq($avant))->toBeTrue();
});
