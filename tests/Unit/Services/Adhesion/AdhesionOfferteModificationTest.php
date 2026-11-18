<?php

declare(strict_types=1);

use App\Models\Adhesion;
use App\Models\FormuleAdhesion;
use App\Models\Tiers;
use App\Models\User;
use App\Services\Adhesion\NouvelleAdhesionDTO;
use App\Services\AdhesionService;
use App\Services\Compta\Migrations\SystemeSeeder;
use Illuminate\Support\Carbon;

/*
 * Une adhésion offerte (sans transaction) se modifie et se supprime depuis la fiche
 * tiers. Avant, elle était figée dès sa création : une erreur de saisie restait sans
 * recours. Une adhésion payée, elle, se corrige toujours par sa transaction.
 */

beforeEach(function (): void {
    SystemeSeeder::seed();
    $this->tiers = Tiers::factory()->create();
    $this->user = User::factory()->create();
    $this->service = app(AdhesionService::class);

    $this->formuleExercice = FormuleAdhesion::factory()->create([
        'nom' => 'Annuelle',
        'mode' => 'exercice',
        'deductible_fiscal' => false,
    ]);
    $this->formuleDuree = FormuleAdhesion::factory()->create([
        'nom' => 'Six mois',
        'mode' => 'duree',
        'duree_mois' => 6,
        'duree_jours' => null,
        'helloasso_start_date' => null,
        'helloasso_end_date' => null,
        'deductible_fiscal' => true,
    ]);
    $this->formuleIllimitee = FormuleAdhesion::factory()->create([
        'nom' => 'Honneur',
        'mode' => 'illimite',
        'duree_mois' => null,
    ]);
});

function offerte(array $attributs = []): Adhesion
{
    return Adhesion::factory()->create(array_merge([
        'tiers_id' => test()->tiers->id,
        'formule_adhesion_id' => test()->formuleExercice->id,
        'exercice' => 2025,
        'date_debut' => '2025-09-01',
        'date_fin' => '2026-08-31',
        'mode' => 'exercice',
        'label_formule' => 'Annuelle',
        'transaction_id' => null,
        'montant_facial' => 0,
        'notes' => 'Président',
    ], $attributs));
}

it('modifie la saison et le motif d\'une adhésion offerte « par exercice », dates recalculées', function (): void {
    $adhesion = offerte();

    $this->service->modifierOfferte($adhesion, (int) $this->formuleExercice->id, 2024, null, 'Co-président');

    $adhesion->refresh();
    expect($adhesion->exercice)->toBe(2024)
        ->and($adhesion->date_debut->toDateString())->toBe('2024-09-01')
        ->and($adhesion->date_fin->toDateString())->toBe('2025-08-31')
        ->and($adhesion->notes)->toBe('Co-président')
        ->and($adhesion->transaction_id)->toBeNull()
        ->and((float) $adhesion->montant_facial)->toBe(0.0);
});

it('change de formule : la période suit la nouvelle formule et l\'instantané est repris', function (): void {
    $adhesion = offerte();

    $this->service->modifierOfferte($adhesion, (int) $this->formuleDuree->id, null, Carbon::parse('2026-01-15'), 'Bénévole');

    $adhesion->refresh();
    expect((int) $adhesion->formule_adhesion_id)->toBe((int) $this->formuleDuree->id)
        ->and($adhesion->mode)->toBe('duree')
        ->and($adhesion->duree_mois)->toBe(6)
        ->and($adhesion->label_formule)->toBe('Six mois')
        ->and($adhesion->deductible_fiscal)->toBeTrue()
        ->and($adhesion->date_debut->toDateString())->toBe('2026-01-15')
        ->and($adhesion->date_fin->toDateString())->toBe('2026-07-14')
        ->and($adhesion->exercice)->toBe(2025);
});

it('garde la date de début transmise pour une formule permanente', function (): void {
    $adhesion = offerte();

    $this->service->modifierOfferte($adhesion, (int) $this->formuleIllimitee->id, null, Carbon::parse('2025-09-01'), 'Honneur');

    $adhesion->refresh();
    expect($adhesion->mode)->toBe('illimite')
        ->and($adhesion->date_debut->toDateString())->toBe('2025-09-01')
        ->and($adhesion->date_fin)->toBeNull()
        ->and($adhesion->exercice)->toBe(2025);
});

it('accepte de garder la même saison : l\'adhésion ne se bloque pas elle-même', function (): void {
    $adhesion = offerte();

    $this->service->modifierOfferte($adhesion, (int) $this->formuleExercice->id, 2025, null, 'Motif corrigé');

    expect($adhesion->refresh()->notes)->toBe('Motif corrigé');
});

it('accepte de décaler une adhésion en durée sur une période qui chevauche son ancienne période', function (): void {
    $adhesion = offerte([
        'formule_adhesion_id' => $this->formuleDuree->id,
        'mode' => 'duree',
        'date_debut' => '2026-01-01',
        'date_fin' => '2026-06-30',
    ]);

    $this->service->modifierOfferte($adhesion, (int) $this->formuleDuree->id, null, Carbon::parse('2026-02-01'), 'Président');

    expect($adhesion->refresh()->date_debut->toDateString())->toBe('2026-02-01');
});

it('refuse une saison déjà occupée par une autre adhésion du tiers', function (): void {
    offerte(['exercice' => 2024, 'date_debut' => '2024-09-01', 'date_fin' => '2025-08-31']);
    $adhesion = offerte();

    expect(fn () => $this->service->modifierOfferte($adhesion, (int) $this->formuleExercice->id, 2024, null, 'X'))
        ->toThrow(DomainException::class, 'déjà une adhésion');

    expect($adhesion->refresh()->exercice)->toBe(2025);
});

it('refuse une période qui chevauche une autre adhésion du tiers', function (): void {
    offerte([
        'exercice' => 2024,
        'formule_adhesion_id' => $this->formuleDuree->id,
        'mode' => 'duree',
        'date_debut' => '2025-03-01',
        'date_fin' => '2025-08-31',
    ]);
    $adhesion = offerte();

    expect(fn () => $this->service->modifierOfferte($adhesion, (int) $this->formuleDuree->id, null, Carbon::parse('2025-06-01'), 'X'))
        ->toThrow(DomainException::class, 'chevauche');
});

it('traduit en refus lisible une collision sur la clé unique', function (): void {
    // Une adhésion permanente n'a pas de fin : la garde de chevauchement ne la voit pas,
    // c'est la clé (tiers, saison, date de début) qui refuse.
    offerte([
        'formule_adhesion_id' => $this->formuleIllimitee->id,
        'mode' => 'illimite',
        'date_debut' => '2025-10-01',
        'date_fin' => null,
    ]);
    $adhesion = offerte(['exercice' => 2024, 'date_debut' => '2024-09-01', 'date_fin' => '2025-08-31']);

    expect(fn () => $this->service->modifierOfferte($adhesion, (int) $this->formuleIllimitee->id, null, Carbon::parse('2025-10-01'), 'X'))
        ->toThrow(DomainException::class, 'déjà une adhésion sur cette période');
});

it('refuse de modifier une adhésion payée', function (): void {
    $payee = Adhesion::factory()->payee()->create(['tiers_id' => $this->tiers->id, 'exercice' => 2025, 'notes' => 'Payée']);

    expect(fn () => $this->service->modifierOfferte($payee, (int) $this->formuleExercice->id, 2024, null, 'X'))
        ->toThrow(DomainException::class);

    expect($payee->refresh()->exercice)->toBe(2025);
});

it('supprime une adhésion offerte (suppression réversible)', function (): void {
    $adhesion = offerte();

    $this->service->supprimerOfferte($adhesion);

    expect(Adhesion::find($adhesion->id))->toBeNull()
        ->and(Adhesion::withTrashed()->find($adhesion->id)?->trashed())->toBeTrue();
});

it('refuse de supprimer une adhésion payée', function (): void {
    $payee = Adhesion::factory()->payee()->create(['tiers_id' => $this->tiers->id]);

    expect(fn () => $this->service->supprimerOfferte($payee))->toThrow(DomainException::class);

    expect(Adhesion::find($payee->id))->not->toBeNull();
});

it('une saison dont l\'adhésion a été supprimée peut être ressaisie par l\'assistant', function (): void {
    $this->service->supprimerOfferte(offerte());

    $nouvelle = $this->service->creerDepuisWizard(new NouvelleAdhesionDTO(
        tiersId: (int) $this->tiers->id,
        formuleId: (int) $this->formuleExercice->id,
        exercice: 2025,
        dateDebut: null,
        montant: 0,
        notes: 'Ressaisie',
        datePaiement: null,
        modePaiement: null,
        compteId: null,
        reference: null,
    ), $this->user);

    expect($nouvelle->exercice)->toBe(2025)
        ->and(Adhesion::where('tiers_id', $this->tiers->id)->count())->toBe(1)
        ->and(Adhesion::withTrashed()->where('tiers_id', $this->tiers->id)->count())->toBe(2);
});
