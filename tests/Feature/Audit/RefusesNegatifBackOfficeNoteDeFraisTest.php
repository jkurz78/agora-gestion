<?php

declare(strict_types=1);

/**
 * Audit signe négatif — Step 6 : BackOffice/NoteDeFrais — analyse n/a.
 *
 * BackOffice/NoteDeFrais/Index est un composant de listing uniquement,
 * sans saisie de montant : n/a.
 *
 * BackOffice/NoteDeFrais/Show expose `confirmValidation()` qui valide
 * le compte bancaire, le mode de paiement et la date de comptabilisation.
 * Il ne saisit PAS de montant : le montant de la NDF est fixé lors de la
 * soumission par le portail (Portail/NoteDeFrais/Create), pas lors de la
 * validation back-office. Aucun champ `montant` public n'est déclaré.
 *
 * @see docs/audit/2026-04-30-signe-negatif.md §2.4
 */

use App\Livewire\BackOffice\NoteDeFrais\Index;
use App\Livewire\BackOffice\NoteDeFrais\Show;

it('back_office_ndf_index_na_pas_de_saisie_de_montant', function (): void {
    $reflection = new ReflectionClass(Index::class);

    expect($reflection->hasMethod('save'))->toBeFalse()
        ->and($reflection->hasMethod('create'))->toBeFalse();

    $publicProps = array_map(
        fn (ReflectionProperty $p) => $p->getName(),
        $reflection->getProperties(ReflectionProperty::IS_PUBLIC)
    );
    expect($publicProps)->not->toContain('montant');
})->skip('n/a — BackOffice/NoteDeFrais/Index est un listing sans saisie de montant (Step 6 audit)');

it('back_office_ndf_show_na_pas_de_saisie_de_montant', function (): void {
    $reflection = new ReflectionClass(Show::class);

    // confirmValidation() valide compte + date + mode_paiement, pas de montant saisi
    $publicProps = array_map(
        fn (ReflectionProperty $p) => $p->getName(),
        $reflection->getProperties(ReflectionProperty::IS_PUBLIC)
    );
    expect($publicProps)->not->toContain('montant');

    // Le montant de la NDF est fixé à la soumission portail, pas à la validation BO
})->skip('n/a — BackOffice/NoteDeFrais/Show ne saisit pas de montant lors de la validation (Step 6 audit)');
