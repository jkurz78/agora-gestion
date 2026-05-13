<?php

declare(strict_types=1);

use App\Enums\Civilite;
use App\Livewire\ImportCsvTiers;
use App\Models\Association;
use App\Models\Tiers;
use App\Models\User;
use App\Tenant\TenantContext;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->association = Association::factory()->create();
    $this->user = User::factory()->create();
    $this->user->associations()->attach($this->association->id, ['role' => 'admin', 'joined_at' => now()]);
    TenantContext::boot($this->association);
    session(['current_association_id' => $this->association->id]);
    $this->actingAs($this->user);
});

afterEach(function (): void {
    TenantContext::clear();
});

/**
 * Helper: make a CSV file with semicolon separator (matching existing tests convention).
 */
function makeCsvWithCivilite(string $content, string $filename = 'tiers.csv'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($filename, $content);
}

it('importe la civilité depuis la colonne CSV', function (): void {
    $csv = "civilite;nom;prenom;email\nM.;Dupont;Jean;jean@example.com\nMme;Kurz;Anne;anne@example.com\n;Solo;Sans;sans@example.com\n";
    $file = makeCsvWithCivilite($csv);

    Livewire::test(ImportCsvTiers::class)
        ->set('showPanel', true)
        ->set('importFile', $file)
        ->call('analyzeFile')
        ->assertSet('phase', 'preview')
        ->call('confirmImport')
        ->assertSet('phase', 'done');

    expect(Tiers::where('nom', 'Dupont')->first()?->civilite)->toBe(Civilite::M)
        ->and(Tiers::where('nom', 'Kurz')->first()?->civilite)->toBe(Civilite::Mme)
        ->and(Tiers::where('nom', 'Solo')->first()?->civilite)->toBeNull();
});

it('reconnaît les variantes Monsieur/Madame', function (): void {
    $csv = "civilite;nom;prenom;email\nMonsieur;Leroi;Paul;paul@example.com\nMADAME;Bernard;Claire;claire@example.com\n";
    $file = makeCsvWithCivilite($csv);

    Livewire::test(ImportCsvTiers::class)
        ->set('showPanel', true)
        ->set('importFile', $file)
        ->call('analyzeFile')
        ->assertSet('phase', 'preview')
        ->call('confirmImport')
        ->assertSet('phase', 'done');

    expect(Tiers::where('nom', 'Leroi')->first()?->civilite)->toBe(Civilite::M)
        ->and(Tiers::where('nom', 'Bernard')->first()?->civilite)->toBe(Civilite::Mme);
});

it('ignore silencieusement les valeurs civilité non reconnues', function (): void {
    $csv = "civilite;nom;prenom;email\nDr;Bidon;Test;test@example.com\n";
    $file = makeCsvWithCivilite($csv);

    Livewire::test(ImportCsvTiers::class)
        ->set('showPanel', true)
        ->set('importFile', $file)
        ->call('analyzeFile')
        ->assertSet('phase', 'preview')
        ->call('confirmImport')
        ->assertSet('phase', 'done');

    expect(Tiers::where('nom', 'Bidon')->first()?->civilite)->toBeNull();
});

it('accepte la colonne avec accent civilité', function (): void {
    $csv = "civilité;nom;prenom;email\nMme;Martin;Julie;julie@example.com\n";
    $file = makeCsvWithCivilite($csv);

    Livewire::test(ImportCsvTiers::class)
        ->set('showPanel', true)
        ->set('importFile', $file)
        ->call('analyzeFile')
        ->assertSet('phase', 'preview')
        ->call('confirmImport')
        ->assertSet('phase', 'done');

    expect(Tiers::where('nom', 'Martin')->first()?->civilite)->toBe(Civilite::Mme);
});
