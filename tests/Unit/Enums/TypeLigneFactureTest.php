<?php

declare(strict_types=1);

use App\Enums\TypeLigneFacture;

describe('TypeLigneFacture enum', function (): void {
    it('has the three expected cases with correct string values', function (): void {
        expect(TypeLigneFacture::Montant->value)->toBe('montant');
        expect(TypeLigneFacture::MontantLibre->value)->toBe('montant_libre');
        expect(TypeLigneFacture::Texte->value)->toBe('texte');
    });

    describe('genereTransactionLigne()', function (): void {
        it('returns false for Montant', function (): void {
            expect(TypeLigneFacture::Montant->genereTransactionLigne())->toBeFalse();
        });

        it('returns true for MontantLibre', function (): void {
            expect(TypeLigneFacture::MontantLibre->genereTransactionLigne())->toBeTrue();
        });

        it('returns false for Texte', function (): void {
            expect(TypeLigneFacture::Texte->genereTransactionLigne())->toBeFalse();
        });
    });

    describe('aImpactComptable()', function (): void {
        it('returns true for Montant', function (): void {
            expect(TypeLigneFacture::Montant->aImpactComptable())->toBeTrue();
        });

        it('returns true for MontantLibre', function (): void {
            expect(TypeLigneFacture::MontantLibre->aImpactComptable())->toBeTrue();
        });

        it('returns false for Texte', function (): void {
            expect(TypeLigneFacture::Texte->aImpactComptable())->toBeFalse();
        });
    });
});
