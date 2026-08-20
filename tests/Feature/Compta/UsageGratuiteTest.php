<?php

declare(strict_types=1);

use App\Enums\TypeCategorie;
use App\Enums\UsageComptable;
use App\Models\Compte;
use App\Services\UsagesComptablesService;
use App\Tenant\TenantContext;

it('expose un usage Gratuite mono, de polarité recette', function (): void {
    $usage = UsageComptable::Gratuite;

    expect($usage->value)->toBe('gratuite')
        ->and($usage->label())->toBe('Gratuités accordées')
        ->and($usage->polarite())->toBe(TypeCategorie::Recette)
        ->and($usage->cardinalite())->toBe('mono');
});

it('setGratuite rattache un compte de classe 7 et remplace le précédent', function (): void {
    $associationId = TenantContext::currentId();

    $premier = Compte::factory()->create([
        'association_id' => $associationId,
        'numero_pcg' => '709A',
        'intitule' => 'Gratuités accordées',
        'classe' => 7,
    ]);
    $second = Compte::factory()->create([
        'association_id' => $associationId,
        'numero_pcg' => '709B',
        'intitule' => 'Autres gratuités',
        'classe' => 7,
    ]);

    $service = app(UsagesComptablesService::class);

    $service->setGratuite($premier->id);
    expect(Compte::forUsage(UsageComptable::Gratuite)->pluck('id')->all())
        ->toBe([$premier->id]);

    // Cardinalité mono : le second remplace le premier, il ne s'y ajoute pas.
    $service->setGratuite($second->id);
    expect(Compte::forUsage(UsageComptable::Gratuite)->pluck('id')->all())
        ->toBe([$second->id]);
});

it('setGratuite(null) détache le compte sans en désigner d’autre', function (): void {
    $associationId = TenantContext::currentId();

    $compte = Compte::factory()->create([
        'association_id' => $associationId,
        'numero_pcg' => '709A',
        'intitule' => 'Gratuités accordées',
        'classe' => 7,
    ]);

    $service = app(UsagesComptablesService::class);
    $service->setGratuite($compte->id);
    $service->setGratuite(null);

    expect(Compte::forUsage(UsageComptable::Gratuite)->count())->toBe(0);
});
