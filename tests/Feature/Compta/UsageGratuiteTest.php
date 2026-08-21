<?php

declare(strict_types=1);

use App\Enums\TypeCategorie;
use App\Enums\UsageComptable;
use App\Models\Compte;
use App\Services\UsagesComptablesService;
use App\Tenant\TenantContext;
use DomainException;

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

it('refuse un compte qui n’est pas de classe 7', function (): void {
    // Le 709A est un contra-produit : il vit au débit mais reste un compte de
    // PRODUITS. EcritureGenerator::pourRecetteACredit rejette toute ventilation de
    // classe ≠ 7 sur une recette — un compte de charge configuré ici ferait échouer
    // la conversion de chaque commande remisée, loin de la configuration fautive.
    //
    // L'écran ne propose que des comptes de produits, mais une requête Livewire
    // forgée contournerait ce filtre : la garde doit être au service.
    $compteCharge = Compte::factory()->create([
        'association_id' => TenantContext::currentId(),
        'numero_pcg' => '628C',
        'intitule' => 'Développement logiciel',
        'classe' => 6,
    ]);

    expect(fn () => app(UsagesComptablesService::class)->setGratuite($compteCharge->id))
        ->toThrow(DomainException::class, 'classe 7');

    // Et surtout : aucun usage ne doit avoir été posé malgré le rejet.
    expect(Compte::forUsage(UsageComptable::Gratuite)->count())->toBe(0);
});
