<?php

declare(strict_types=1);

use App\Enums\TypeLigneFacture;

describe('TypeLigneFacture enum', function (): void {
    it('has the three expected cases with correct string values', function (): void {
        expect(TypeLigneFacture::Montant->value)->toBe('montant');
        expect(TypeLigneFacture::MontantManuel->value)->toBe('montant_manuel');
        expect(TypeLigneFacture::Texte->value)->toBe('texte');
    });

    describe('genereTransactionLigne()', function (): void {
        it('returns false for Montant', function (): void {
            expect(TypeLigneFacture::Montant->genereTransactionLigne())->toBeFalse();
        });

        it('returns true for MontantManuel', function (): void {
            expect(TypeLigneFacture::MontantManuel->genereTransactionLigne())->toBeTrue();
        });

        it('returns false for Texte', function (): void {
            expect(TypeLigneFacture::Texte->genereTransactionLigne())->toBeFalse();
        });
    });

    describe('aImpactComptable()', function (): void {
        it('returns true for Montant', function (): void {
            expect(TypeLigneFacture::Montant->aImpactComptable())->toBeTrue();
        });

        it('returns true for MontantManuel', function (): void {
            expect(TypeLigneFacture::MontantManuel->aImpactComptable())->toBeTrue();
        });

        it('returns false for Texte', function (): void {
            expect(TypeLigneFacture::Texte->aImpactComptable())->toBeFalse();
        });
    });
});
