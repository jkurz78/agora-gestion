<?php

declare(strict_types=1);

use App\Enums\StatutNoteDeFrais;
use App\Models\NoteDeFrais;
use App\Services\NoteDeFrais\NoteDeFraisValidationService;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

beforeEach(function (): void {
    $this->service = app(NoteDeFraisValidationService::class);
});

it('rejects a soumise ndf with a non-empty motif', function (): void {
    $ndf = NoteDeFrais::factory()->soumise()->create();

    $this->service->rejeter($ndf, 'Justificatif manquant');

    $ndf->refresh();
    expect($ndf->getRawOriginal('statut'))->toBe(StatutNoteDeFrais::Rejetee->value);
    expect($ndf->motif_rejet)->toBe('Justificatif manquant');
});

it('throws ValidationException when motif is empty', function (): void {
    $ndf = NoteDeFrais::factory()->soumise()->create();

    expect(fn () => $this->service->rejeter($ndf, ''))
        ->toThrow(ValidationException::class, 'Le motif est obligatoire.');
});

it('throws ValidationException when motif is whitespace only', function (): void {
    $ndf = NoteDeFrais::factory()->soumise()->create();

    // Whitespace-only string: 'required' rule fails for strings that are only spaces
    expect(fn () => $this->service->rejeter($ndf, '   '))
        ->toThrow(ValidationException::class, 'Le motif est obligatoire.');
});

it('throws DomainException when ndf is in brouillon', function (): void {
    $ndf = NoteDeFrais::factory()->brouillon()->create();

    expect(fn () => $this->service->rejeter($ndf, 'Un motif valide'))
        ->toThrow(DomainException::class, 'Seule une NDF soumise peut être rejetée');
});

it('throws DomainException when ndf is already rejetee', function (): void {
    $ndf = NoteDeFrais::factory()->create([
        'statut' => StatutNoteDeFrais::Rejetee->value,
        'motif_rejet' => 'Premier rejet',
    ]);

    expect(fn () => $this->service->rejeter($ndf, 'Deuxième tentative'))
        ->toThrow(DomainException::class, 'Seule une NDF soumise peut être rejetée');
});

it('throws DomainException when ndf is already validee', function (): void {
    $ndf = NoteDeFrais::factory()->validee()->create();

    expect(fn () => $this->service->rejeter($ndf, 'Tentative sur validée'))
        ->toThrow(DomainException::class, 'Seule une NDF soumise peut être rejetée');
});

it('emits comptabilite.ndf.rejected log', function (): void {
    $spy = Log::spy();

    $ndf = NoteDeFrais::factory()->soumise()->create();

    $this->service->rejeter($ndf, 'Montant incorrect');

    $spy->shouldHaveReceived('info')
        ->with(
            'comptabilite.ndf.rejected',
            Mockery::on(fn ($ctx) => (int) ($ctx['ndf_id'] ?? 0) === (int) $ndf->id
                && $ctx['motif'] === 'Montant incorrect')
        )
        ->once();
});
