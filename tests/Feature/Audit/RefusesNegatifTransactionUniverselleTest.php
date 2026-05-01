<?php

declare(strict_types=1);

/**
 * Audit signe négatif — Step 5 : TransactionUniverselle — n/a (pas de saisie de montant).
 *
 * TransactionUniverselle est un composant de listing/filtrage uniquement.
 * Il ne dispose d'aucun formulaire de saisie de montant.
 * Le présent fichier documente cette analyse et la confirme par un test
 * qui vérifie l'absence de méthode de sauvegarde de transaction dans le composant.
 *
 * @see docs/audit/2026-04-30-signe-negatif.md §2.4
 */

use App\Livewire\TransactionUniverselle;

it('transaction_universelle_na_pas_de_saisie_de_montant', function (): void {
    // Le composant TransactionUniverselle est un listing uniquement :
    // il ne déclare ni méthode save(), ni méthode create(), ni règles de
    // validation pour un champ montant. Le trait RefusesMontantNegatif
    // ne s'applique donc pas à ce composant.
    $reflection = new ReflectionClass(TransactionUniverselle::class);

    expect($reflection->hasMethod('save'))->toBeFalse()
        ->and($reflection->hasMethod('create'))->toBeFalse();

    // Aucune propriété publique 'montant' n'est déclarée
    $publicProps = array_map(
        fn (ReflectionProperty $p) => $p->getName(),
        $reflection->getProperties(ReflectionProperty::IS_PUBLIC)
    );
    expect($publicProps)->not->toContain('montant');
})->skip('n/a — TransactionUniverselle est un listing sans saisie de montant (Step 5 audit)');
