<?php

declare(strict_types=1);

use App\Enums\ModePaiement;
use App\Enums\StatutExercice;
use App\Enums\StatutNoteDeFrais;
use App\Enums\StatutReglement;
use App\Enums\TypeTransaction;
use App\Exceptions\ExerciceCloturedException;
use App\Models\CompteBancaire;
use App\Models\Exercice;
use App\Models\NoteDeFrais;
use App\Models\NoteDeFraisLigne;
use App\Models\SousCategorie;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Services\NoteDeFrais\NoteDeFraisValidationService;
use App\Services\NoteDeFrais\ValidationData;
use App\Tenant\TenantContext;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake('local');
    $this->service = app(NoteDeFraisValidationService::class);

    $this->compte = CompteBancaire::factory()->create();
    $this->validationData = new ValidationData(
        compte_id: (int) $this->compte->id,
        mode_paiement: ModePaiement::Virement,
        date: '2025-10-15',
    );
});

// ---------------------------------------------------------------------------
// Happy path — Transaction created
// ---------------------------------------------------------------------------

it('creates a Transaction of type Depense when validating a soumise NDF', function (): void {
    $tiers = Tiers::factory()->create();
    $ndf = NoteDeFrais::factory()->soumise()->create([
        'tiers_id' => $tiers->id,
        'libelle' => 'Frais déplacement Paris',
    ]);
    $sousCategorie = SousCategorie::factory()->create(['pour_inscriptions' => false]);
    NoteDeFraisLigne::factory()->create([
        'note_de_frais_id' => $ndf->id,
        'sous_categorie_id' => $sousCategorie->id,
        'libelle' => 'Billet train',
        'montant' => '45.00',
        'piece_jointe_path' => null,
    ]);

    $transaction = $this->service->valider($ndf, $this->validationData);

    expect($transaction)->toBeInstanceOf(Transaction::class);
    expect($transaction->type)->toBe(TypeTransaction::Depense);
    expect($transaction->statut_reglement)->toBe(StatutReglement::EnAttente);
    expect((int) $transaction->tiers_id)->toBe((int) $tiers->id);
    expect($transaction->libelle)->toBe('Frais déplacement Paris');
    expect($transaction->date->format('Y-m-d'))->toBe('2025-10-15');
    expect((int) $transaction->compte_id)->toBe((int) $this->compte->id);
    expect($transaction->mode_paiement)->toBe(ModePaiement::Virement);
});

it('creates one TransactionLigne per NDF ligne with correct values', function (): void {
    $tiers = Tiers::factory()->create();
    $ndf = NoteDeFrais::factory()->soumise()->create(['tiers_id' => $tiers->id]);
    $sousCategorie1 = SousCategorie::factory()->create(['pour_inscriptions' => false]);
    $sousCategorie2 = SousCategorie::factory()->create(['pour_inscriptions' => false]);

    NoteDeFraisLigne::factory()->create([
        'note_de_frais_id' => $ndf->id,
        'sous_categorie_id' => $sousCategorie1->id,
        'libelle' => 'Train Paris',
        'montant' => '89.50',
        'seance' => 3,
        'piece_jointe_path' => null,
    ]);
    NoteDeFraisLigne::factory()->create([
        'note_de_frais_id' => $ndf->id,
        'sous_categorie_id' => $sousCategorie2->id,
        'libelle' => 'Hotel nuit',
        'montant' => '120.00',
        'seance' => null,
        'piece_jointe_path' => null,
    ]);

    $transaction = $this->service->valider($ndf, $this->validationData);
    $transaction->load('lignes');

    expect($transaction->lignes)->toHaveCount(2);

    $ligne1 = $transaction->lignes->firstWhere('montant', '89.50');
    expect($ligne1)->not->toBeNull();
    expect((int) $ligne1->sous_categorie_id)->toBe((int) $sousCategorie1->id);
    expect($ligne1->notes)->toBe('Train Paris');
    expect($ligne1->seance)->toBe(3);

    $ligne2 = $transaction->lignes->firstWhere('montant', '120.00');
    expect($ligne2)->not->toBeNull();
    expect((int) $ligne2->sous_categorie_id)->toBe((int) $sousCategorie2->id);
    expect($ligne2->seance)->toBeNull();
});

it('sets montant_total to sum of all NDF lignes', function (): void {
    $ndf = NoteDeFrais::factory()->soumise()->create();
    $sousCategorie = SousCategorie::factory()->create(['pour_inscriptions' => false]);
    NoteDeFraisLigne::factory()->create([
        'note_de_frais_id' => $ndf->id,
        'sous_categorie_id' => $sousCategorie->id,
        'montant' => '50.00',
        'piece_jointe_path' => null,
    ]);
    NoteDeFraisLigne::factory()->create([
        'note_de_frais_id' => $ndf->id,
        'sous_categorie_id' => $sousCategorie->id,
        'montant' => '75.50',
        'piece_jointe_path' => null,
    ]);

    $transaction = $this->service->valider($ndf, $this->validationData);

    expect((float) $transaction->montant_total)->toBe(125.50);
});

it('updates NDF to Validee with transaction_id and validee_at', function (): void {
    $ndf = NoteDeFrais::factory()->soumise()->create();
    $sousCategorie = SousCategorie::factory()->create(['pour_inscriptions' => false]);
    NoteDeFraisLigne::factory()->create([
        'note_de_frais_id' => $ndf->id,
        'sous_categorie_id' => $sousCategorie->id,
        'montant' => '30.00',
        'piece_jointe_path' => null,
    ]);

    $transaction = $this->service->valider($ndf, $this->validationData);

    $ndf->refresh();
    expect($ndf->getRawOriginal('statut'))->toBe(StatutNoteDeFrais::Validee->value);
    expect((int) $ndf->transaction_id)->toBe((int) $transaction->id);
    expect($ndf->validee_at)->not->toBeNull();
});

// ---------------------------------------------------------------------------
// PJ copy
// ---------------------------------------------------------------------------

it('copies PJ from NDF ligne to transaction ligne path', function (): void {
    $ndf = NoteDeFrais::factory()->soumise()->create();
    $sousCategorie = SousCategorie::factory()->create(['pour_inscriptions' => false]);

    // Create a fake source file
    $assocId = TenantContext::currentId();
    $sourcePath = "associations/{$assocId}/notes-de-frais/{$ndf->id}/ligne-99.pdf";
    Storage::disk('local')->put($sourcePath, 'fake-pdf-content');

    NoteDeFraisLigne::factory()->create([
        'note_de_frais_id' => $ndf->id,
        'sous_categorie_id' => $sousCategorie->id,
        'libelle' => 'Billet avion',
        'montant' => '200.00',
        'piece_jointe_path' => $sourcePath,
    ]);

    $transaction = $this->service->valider($ndf, $this->validationData);
    $transaction->load('lignes');

    $ligne = $transaction->lignes->first();
    expect($ligne->piece_jointe_path)->not->toBeNull();

    // Verify it was actually copied
    Storage::disk('local')->assertExists($ligne->piece_jointe_path);

    // Verify path format: associations/{id}/transactions/{tr_id}/ligne-1-{slug}.pdf
    expect($ligne->piece_jointe_path)->toMatch(
        '/^associations\/\d+\/transactions\/\d+\/ligne-1-[a-z0-9-]+\.pdf$/'
    );
});

it('copies multiple PJs with correct 1-based index in path', function (): void {
    $ndf = NoteDeFrais::factory()->soumise()->create();
    $sousCategorie = SousCategorie::factory()->create(['pour_inscriptions' => false]);
    $assocId = TenantContext::currentId();

    $source1 = "associations/{$assocId}/notes-de-frais/{$ndf->id}/ligne-1.pdf";
    $source2 = "associations/{$assocId}/notes-de-frais/{$ndf->id}/ligne-2.jpg";
    Storage::disk('local')->put($source1, 'pdf-content');
    Storage::disk('local')->put($source2, 'jpg-content');

    NoteDeFraisLigne::factory()->create([
        'note_de_frais_id' => $ndf->id,
        'sous_categorie_id' => $sousCategorie->id,
        'libelle' => 'Repas client',
        'montant' => '45.00',
        'piece_jointe_path' => $source1,
    ]);
    NoteDeFraisLigne::factory()->create([
        'note_de_frais_id' => $ndf->id,
        'sous_categorie_id' => $sousCategorie->id,
        'libelle' => 'Parking',
        'montant' => '12.00',
        'piece_jointe_path' => $source2,
    ]);

    $transaction = $this->service->valider($ndf, $this->validationData);
    $transaction->load('lignes');

    $paths = $transaction->lignes->pluck('piece_jointe_path');
    expect($paths->filter(fn ($p) => str_contains((string) $p, 'ligne-1-')))->toHaveCount(1);
    expect($paths->filter(fn ($p) => str_contains((string) $p, 'ligne-2-')))->toHaveCount(1);
});

it('leaves piece_jointe_path null on transaction ligne when NDF ligne has no PJ', function (): void {
    $ndf = NoteDeFrais::factory()->soumise()->create();
    $sousCategorie = SousCategorie::factory()->create(['pour_inscriptions' => false]);
    NoteDeFraisLigne::factory()->create([
        'note_de_frais_id' => $ndf->id,
        'sous_categorie_id' => $sousCategorie->id,
        'montant' => '30.00',
        'piece_jointe_path' => null,
    ]);

    $transaction = $this->service->valider($ndf, $this->validationData);
    $transaction->load('lignes');

    expect($transaction->lignes->first()->piece_jointe_path)->toBeNull();
});

// ---------------------------------------------------------------------------
// Domain guards
// ---------------------------------------------------------------------------

it('throws DomainException when NDF is in Brouillon', function (): void {
    $ndf = NoteDeFrais::factory()->brouillon()->create();

    expect(fn () => $this->service->valider($ndf, $this->validationData))
        ->toThrow(DomainException::class, 'Seule une NDF soumise peut être validée');
});

it('throws DomainException when NDF is already Validee', function (): void {
    $ndf = NoteDeFrais::factory()->validee()->create();

    expect(fn () => $this->service->valider($ndf, $this->validationData))
        ->toThrow(DomainException::class, 'Seule une NDF soumise peut être validée');
});

it('throws ExerciceCloturedException when date falls in closed exercice', function (): void {
    $assocId = TenantContext::currentId();
    $ndf = NoteDeFrais::factory()->soumise()->create();
    $sousCategorie = SousCategorie::factory()->create(['pour_inscriptions' => false]);
    NoteDeFraisLigne::factory()->create([
        'note_de_frais_id' => $ndf->id,
        'sous_categorie_id' => $sousCategorie->id,
        'montant' => '30.00',
        'piece_jointe_path' => null,
    ]);

    // Create a clôturé exercice 2023 (covers sept 2023 – août 2024)
    Exercice::create([
        'association_id' => $assocId,
        'annee' => 2023,
        'statut' => StatutExercice::Cloture,
    ]);

    $data = new ValidationData(
        compte_id: (int) $this->compte->id,
        mode_paiement: ModePaiement::Virement,
        date: '2024-03-15', // March 2024 → exercice 2023 (sept 2023 – août 2024)
    );

    expect(fn () => $this->service->valider($ndf, $data))
        ->toThrow(ExerciceCloturedException::class);
});

// ---------------------------------------------------------------------------
// Rollback on missing source PJ
// ---------------------------------------------------------------------------

it('rolls back entirely when source PJ file is missing', function (): void {
    $ndf = NoteDeFrais::factory()->soumise()->create();
    $sousCategorie = SousCategorie::factory()->create(['pour_inscriptions' => false]);
    $assocId = TenantContext::currentId();

    // Reference a source that does NOT exist in Storage::fake
    NoteDeFraisLigne::factory()->create([
        'note_de_frais_id' => $ndf->id,
        'sous_categorie_id' => $sousCategorie->id,
        'libelle' => 'Repas',
        'montant' => '55.00',
        'piece_jointe_path' => "associations/{$assocId}/notes-de-frais/{$ndf->id}/missing.pdf",
    ]);

    $initialTransactionCount = Transaction::count();

    expect(fn () => $this->service->valider($ndf, $this->validationData))
        ->toThrow(RuntimeException::class);

    // NDF must still be Soumise
    $ndf->refresh();
    expect($ndf->getRawOriginal('statut'))->toBe(StatutNoteDeFrais::Soumise->value);
    expect($ndf->transaction_id)->toBeNull();
    expect($ndf->validee_at)->toBeNull();

    // No new Transaction created
    expect(Transaction::count())->toBe($initialTransactionCount);
});

// ---------------------------------------------------------------------------
// Log emission
// ---------------------------------------------------------------------------

it('emits comptabilite.ndf.validated log with correct context', function (): void {
    $ndf = NoteDeFrais::factory()->soumise()->create();
    $sousCategorie = SousCategorie::factory()->create(['pour_inscriptions' => false]);
    NoteDeFraisLigne::factory()->create([
        'note_de_frais_id' => $ndf->id,
        'sous_categorie_id' => $sousCategorie->id,
        'montant' => '80.00',
        'piece_jointe_path' => null,
    ]);

    $spy = Log::spy();
    $transaction = $this->service->valider($ndf, $this->validationData);

    // Capture IDs as plain integers before the closure to avoid Mockery evaluation issues
    $expectedNdfId = (int) $ndf->id;
    $expectedTxId = (int) $transaction->id;

    $spy->shouldHaveReceived('info')
        ->with(
            'comptabilite.ndf.validated',
            Mockery::on(fn ($ctx) => (int) ($ctx['ndf_id'] ?? 0) === $expectedNdfId
                && (int) ($ctx['transaction_id'] ?? 0) === $expectedTxId
                && array_key_exists('montant_total', $ctx)
                && array_key_exists('valide_par', $ctx)
            )
        )
        ->once();
});
