<?php

declare(strict_types=1);

use App\Livewire\Immobilisations\ImmobilisationIndex;
use App\Livewire\Immobilisations\ImmobilisationShow;
use App\Models\Compte;
use App\Models\Immobilisation;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Immobilisation\ImmobilisationComptesSeeder;
use App\Tenant\TenantContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/**
 * Une acquisition s'accompagne d'une facture fournisseur qu'on veut conserver
 * (docs/specs/2026-08-04-immobilisations-design.md §7.3). Les transactions
 * savent déjà porter un justificatif (piece_jointe_path/nom/mime) — ce qui
 * manque, c'est la saisie côté ImmobilisationIndex et l'accès depuis la fiche.
 */
beforeEach(function (): void {
    $association = TenantContext::current();
    $user = User::factory()->create();
    $user->associations()->attach($association->id, ['role' => 'admin', 'joined_at' => now()]);
    $this->actingAs($user);

    Compte::factory()->create(['numero_pcg' => '401', 'classe' => 4, 'est_systeme' => true]);
    ImmobilisationComptesSeeder::seed();
});

it('dépose un justificatif à la création et le retrouve sur la fiche', function (): void {
    Storage::fake('local');
    $tiers = Tiers::factory()->create();
    $file = UploadedFile::fake()->create('facture-videoprojecteur.pdf', 100, 'application/pdf');

    Livewire::test(ImmobilisationIndex::class)
        ->call('ouvrirModal')
        ->set('libelle', 'Vidéoprojecteur')
        ->set('quantite', 1)
        ->set('compte_id', (string) Compte::ofNumero('2188')->id)
        ->set('tiers_id', (int) $tiers->id)
        ->set('montant', '450.00')
        ->set('date_achat', '2026-09-12')
        ->set('date_mise_en_service', '2026-09-12')
        ->set('duree_mois', 60)
        ->set('pieceJointeAcquisition', $file)
        ->call('enregistrer')
        ->assertHasNoErrors();

    $immo = Immobilisation::firstOrFail();
    $tx = $immo->transaction;

    expect($tx->hasPieceJointe())->toBeTrue()
        ->and($tx->piece_jointe_nom)->toBe('facture-videoprojecteur.pdf')
        ->and($tx->piece_jointe_mime)->toBe('application/pdf')
        ->and(Storage::disk('local')->exists($tx->pieceJointeFullPath()))->toBeTrue();

    Livewire::test(ImmobilisationShow::class, ['immobilisation' => $immo->fresh()])
        ->assertSee('facture-videoprojecteur.pdf');
});

it('reste possible sans justificatif', function (): void {
    $tiers = Tiers::factory()->create();

    Livewire::test(ImmobilisationIndex::class)
        ->call('ouvrirModal')
        ->set('libelle', 'Matériel sans PJ')
        ->set('quantite', 1)
        ->set('compte_id', (string) Compte::ofNumero('2188')->id)
        ->set('tiers_id', (int) $tiers->id)
        ->set('montant', '200.00')
        ->set('date_achat', '2026-09-12')
        ->set('date_mise_en_service', '2026-09-12')
        ->set('duree_mois', 36)
        ->call('enregistrer')
        ->assertHasNoErrors();

    $immo = Immobilisation::firstOrFail();
    expect($immo->transaction->hasPieceJointe())->toBeFalse();
});

it('ne laisse ni fiche, ni transaction, ni fichier si l’écriture comptable échoue', function (): void {
    Storage::fake('local');
    $tiers = Tiers::factory()->create();
    $file = UploadedFile::fake()->create('facture.pdf', 100, 'application/pdf');

    // Force un échec réel en pleine transaction DB : pourDepenseACredit() résout
    // Compte::ofNumeroSysteme('401') — sa disparition (soft-delete) déclenche un
    // ModelNotFoundException après la création de la ligne de ventilation classe 2,
    // provoquant le rollback de tout ImmobilisationService::acquerir() (même
    // technique que tests/Feature/Immobilisation/DotationGenerationAtomiqueTest.php).
    Compte::ofNumeroSysteme('401')->delete();

    expect(fn () => Livewire::test(ImmobilisationIndex::class)
        ->call('ouvrirModal')
        ->set('libelle', 'Matériel qui échoue')
        ->set('quantite', 1)
        ->set('compte_id', (string) Compte::ofNumero('2188')->id)
        ->set('tiers_id', (int) $tiers->id)
        ->set('montant', '999.00')
        ->set('date_achat', '2026-09-12')
        ->set('date_mise_en_service', '2026-09-12')
        ->set('duree_mois', 36)
        ->set('pieceJointeAcquisition', $file)
        ->call('enregistrer'))->toThrow(ModelNotFoundException::class);

    expect(Immobilisation::count())->toBe(0)
        ->and(Transaction::where('libelle', 'Matériel qui échoue')->count())->toBe(0);

    $tenantId = (int) TenantContext::currentId();
    $fichiersJustificatifs = collect(Storage::disk('local')->allFiles("associations/{$tenantId}"))
        ->filter(fn (string $f): bool => str_contains($f, 'justificatif'));

    expect($fichiersJustificatifs)->toBeEmpty();
});
