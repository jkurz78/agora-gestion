<?php

declare(strict_types=1);

use App\Enums\UsageComptable;
use App\Models\Association;
use App\Models\Compte;
use App\Models\UsageCompte;
use App\Tenant\TenantContext;

beforeEach(function (): void {
    $this->association = Association::factory()->create();
    TenantContext::boot($this->association);
});

afterEach(function (): void {
    TenantContext::clear();
});

it('crée un compte de produit valide par défaut, sans collision de numéro', function (): void {
    $a = Compte::factory()->create();
    $b = Compte::factory()->create();

    expect((int) $a->classe)->toBe(7)
        ->and($a->numero_pcg)->toStartWith('7')
        ->and(strlen($a->numero_pcg))->toBe(5)
        ->and($a->numero_pcg)->not->toBe($b->numero_pcg)
        ->and((int) $a->association_id)->toBe((int) $this->association->id)
        ->and($a->est_systeme)->toBeFalse()
        ->and($a->actif)->toBeTrue();
});

it('depense() produit un compte de charge classe 6', function (): void {
    $compte = Compte::factory()->depense()->create();

    expect((int) $compte->classe)->toBe(6)
        ->and($compte->numero_pcg)->toStartWith('6');
});

it('numero() pose le numéro exact et dérive la classe', function (): void {
    $produit = Compte::factory()->numero('754')->create();
    $charge = Compte::factory()->numero('606')->create();

    expect($produit->numero_pcg)->toBe('754')
        ->and((int) $produit->classe)->toBe(7)
        ->and($charge->numero_pcg)->toBe('606')
        ->and((int) $charge->classe)->toBe(6);
});

it('pourDons() poste le lien d usage avec compte_id', function (): void {
    $compte = Compte::factory()->numero('754')->pourDons()->create();

    expect($compte->hasUsage(UsageComptable::Don))->toBeTrue();

    $lien = UsageCompte::where('compte_id', $compte->id)
        ->where('usage', UsageComptable::Don->value)
        ->first();
    expect($lien)->not->toBeNull()
        ->and((int) $lien->association_id)->toBe((int) $this->association->id);
});

it('pourInscriptions() pose aussi le flag pour_inscriptions', function (): void {
    $compte = Compte::factory()->numero('706')->pourInscriptions()->create();

    expect($compte->pour_inscriptions)->toBeTrue()
        ->and($compte->hasUsage(UsageComptable::Inscription))->toBeTrue();
});

it('suit le TenantContext courant', function (): void {
    $autre = Association::factory()->create();
    TenantContext::boot($autre);

    $compte = Compte::factory()->create();

    expect((int) $compte->association_id)->toBe((int) $autre->id);

    TenantContext::boot($this->association);
});
