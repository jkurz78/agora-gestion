<?php

declare(strict_types=1);

use App\Enums\JournalComptable;
use App\Enums\StatutExercice;
use App\Enums\TypeTransaction;
use App\Livewire\Provisions\ProvisionIndex;
use App\Models\Association;
use App\Models\Compte;
use App\Models\Exercice;
use App\Models\Provision;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Compta\EcritureGenerator;
use App\Services\Compta\Migrations\SystemeSeeder;
use App\Services\Compta\ProvisionPDService;
use App\Services\ExerciceService;
use App\Tenant\TenantContext;
use Livewire\Livewire;

beforeEach(function () {
    $this->association = Association::factory()->create();
    $this->user = User::factory()->create();
    $this->user->associations()->attach($this->association->id, ['role' => 'admin', 'joined_at' => now()]);
    TenantContext::boot($this->association);
    session(['current_association_id' => $this->association->id]);
    $this->actingAs($this->user);

    SystemeSeeder::seed();

    $this->exercice = Exercice::create(['association_id' => $this->association->id, 'annee' => 2025, 'statut' => StatutExercice::Ouvert]);
    session(['exercice_actif' => 2025]);
});

afterEach(function () {
    TenantContext::clear();
});

test('SystemeSeeder seeds provision accounts 486, 487, 681, 781', function () {
    $assocId = TenantContext::currentId();

    foreach (['486', '487', '681', '781'] as $pcg) {
        $compte = Compte::where('association_id', $assocId)
            ->where('numero_pcg', $pcg)
            ->first();

        expect($compte)->not->toBeNull("Compte {$pcg} not found");
        expect((bool) $compte->est_systeme)->toBeTrue();
        expect((bool) $compte->actif)->toBeTrue();
        expect((bool) $compte->lettrable)->toBeFalse();
    }

    expect((int) Compte::where('association_id', $assocId)->where('numero_pcg', '486')->first()->classe)->toBe(4);
    expect((int) Compte::where('association_id', $assocId)->where('numero_pcg', '487')->first()->classe)->toBe(4);
    expect((int) Compte::where('association_id', $assocId)->where('numero_pcg', '681')->first()->classe)->toBe(6);
    expect((int) Compte::where('association_id', $assocId)->where('numero_pcg', '781')->first()->classe)->toBe(7);
});

test('pourProvisionDotation — dépense generates 681 D / 486 C in journal OD', function () {
    $provision = Provision::factory()->create([
        'association_id' => $this->association->id,
        'exercice' => 2025,
        'type' => 'depense',
        'montant' => 1500.00,
        'libelle' => 'Loyer décembre non facturé',
        'date' => '2026-08-31',
        'saisi_par' => $this->user->id,
    ]);

    $generator = app(EcritureGenerator::class);
    $tx = $generator->pourProvisionDotation($provision);

    expect($tx->provision_id)->toBe((int) $provision->id);
    expect($tx->journal)->toBe(JournalComptable::Od);
    expect($tx->type)->toBe(TypeTransaction::Depense);
    expect($tx->type_ecriture)->toBe('normale');
    expect((float) $tx->montant_total)->toBe(1500.00);
    expect((bool) $tx->equilibree)->toBeTrue();
    expect($tx->lignes)->toHaveCount(2);

    $ligne681 = $tx->lignes->first(fn ($l) => $l->compte->numero_pcg === '681');
    $ligne486 = $tx->lignes->first(fn ($l) => $l->compte->numero_pcg === '486');

    expect($ligne681)->not->toBeNull();
    expect((float) $ligne681->debit)->toBe(1500.00);
    expect((float) $ligne681->credit)->toBe(0.0);

    expect($ligne486)->not->toBeNull();
    expect((float) $ligne486->debit)->toBe(0.0);
    expect((float) $ligne486->credit)->toBe(1500.00);
});

test('pourProvisionDotation — recette generates 487 D / 781 C in journal OD', function () {
    $provision = Provision::factory()->create([
        'association_id' => $this->association->id,
        'exercice' => 2025,
        'type' => 'recette',
        'montant' => 800.00,
        'libelle' => 'Subvention avance N+1',
        'date' => '2026-08-31',
        'saisi_par' => $this->user->id,
    ]);

    $generator = app(EcritureGenerator::class);
    $tx = $generator->pourProvisionDotation($provision);

    expect($tx->journal)->toBe(JournalComptable::Od);
    expect($tx->type)->toBe(TypeTransaction::Recette);

    $ligne487 = $tx->lignes->first(fn ($l) => $l->compte->numero_pcg === '487');
    $ligne781 = $tx->lignes->first(fn ($l) => $l->compte->numero_pcg === '781');

    expect($ligne487)->not->toBeNull();
    expect((float) $ligne487->debit)->toBe(800.00);
    expect((float) $ligne487->credit)->toBe(0.0);

    expect($ligne781)->not->toBeNull();
    expect((float) $ligne781->debit)->toBe(0.0);
    expect((float) $ligne781->credit)->toBe(800.00);
});

test('pourProvisionExtourne — dépense generates 486 D / 781 C, dated 1er sept N+1', function () {
    $provision = Provision::factory()->create([
        'association_id' => $this->association->id,
        'exercice' => 2025,
        'type' => 'depense',
        'montant' => 1500.00,
        'libelle' => 'Loyer décembre non facturé',
        'date' => '2026-08-31',
        'saisi_par' => $this->user->id,
    ]);

    $generator = app(EcritureGenerator::class);
    $tx = $generator->pourProvisionExtourne($provision);

    expect($tx->provision_id)->toBe((int) $provision->id);
    expect($tx->journal)->toBe(JournalComptable::Od);
    expect($tx->type)->toBe(TypeTransaction::Recette);
    expect($tx->type_ecriture)->toBe('extourne');
    expect($tx->date->format('Y-m-d'))->toBe('2026-09-01');
    expect((float) $tx->montant_total)->toBe(1500.00);

    $ligne486 = $tx->lignes->first(fn ($l) => $l->compte->numero_pcg === '486');
    $ligne781 = $tx->lignes->first(fn ($l) => $l->compte->numero_pcg === '781');

    expect($ligne486)->not->toBeNull();
    expect((float) $ligne486->debit)->toBe(1500.00);
    expect((float) $ligne486->credit)->toBe(0.0);

    expect($ligne781)->not->toBeNull();
    expect((float) $ligne781->debit)->toBe(0.0);
    expect((float) $ligne781->credit)->toBe(1500.00);
});

test('pourProvisionExtourne — recette generates 681 D / 487 C, dated 1er sept N+1', function () {
    $provision = Provision::factory()->create([
        'association_id' => $this->association->id,
        'exercice' => 2025,
        'type' => 'recette',
        'montant' => 800.00,
        'libelle' => 'Subvention avance N+1',
        'date' => '2026-08-31',
        'saisi_par' => $this->user->id,
    ]);

    $generator = app(EcritureGenerator::class);
    $tx = $generator->pourProvisionExtourne($provision);

    expect($tx->journal)->toBe(JournalComptable::Od);
    expect($tx->type)->toBe(TypeTransaction::Depense);
    expect($tx->type_ecriture)->toBe('extourne');
    expect($tx->date->format('Y-m-d'))->toBe('2026-09-01');

    $ligne681 = $tx->lignes->first(fn ($l) => $l->compte->numero_pcg === '681');
    $ligne487 = $tx->lignes->first(fn ($l) => $l->compte->numero_pcg === '487');

    expect($ligne681)->not->toBeNull();
    expect((float) $ligne681->debit)->toBe(800.00);

    expect($ligne487)->not->toBeNull();
    expect((float) $ligne487->credit)->toBe(800.00);
});

test('ProvisionPDService::generer creates dotation + extourne for a dépense provision', function () {
    $provision = Provision::factory()->create([
        'association_id' => $this->association->id,
        'exercice' => 2025,
        'type' => 'depense',
        'montant' => 2000.00,
        'libelle' => 'FNP assurance',
        'date' => '2026-08-31',
        'saisi_par' => $this->user->id,
    ]);

    $service = app(ProvisionPDService::class);
    $service->generer($provision);

    $txs = Transaction::where('provision_id', $provision->id)->orderBy('date')->get();
    expect($txs)->toHaveCount(2);

    // Dotation (31 aug)
    $dotation = $txs->first(fn ($t) => $t->type_ecriture === 'normale');
    expect($dotation)->not->toBeNull();
    expect($dotation->date->format('Y-m-d'))->toBe('2026-08-31');
    expect($dotation->journal)->toBe(JournalComptable::Od);

    // Extourne (1 sept)
    $extourne = $txs->first(fn ($t) => $t->type_ecriture === 'extourne');
    expect($extourne)->not->toBeNull();
    expect($extourne->date->format('Y-m-d'))->toBe('2026-09-01');
});

test('ProvisionPDService::generer replaces existing TX on re-call', function () {
    $provision = Provision::factory()->create([
        'association_id' => $this->association->id,
        'exercice' => 2025,
        'type' => 'depense',
        'montant' => 1000.00,
        'libelle' => 'FNP test',
        'date' => '2026-08-31',
        'saisi_par' => $this->user->id,
    ]);

    $service = app(ProvisionPDService::class);
    $service->generer($provision);

    $oldIds = Transaction::where('provision_id', $provision->id)->pluck('id')->toArray();
    expect($oldIds)->toHaveCount(2);

    // Re-generate (simulates update)
    $service->generer($provision);

    // Old TX hard-deleted
    foreach ($oldIds as $id) {
        expect(Transaction::withTrashed()->find($id))->toBeNull();
    }

    // New TX created
    expect(Transaction::where('provision_id', $provision->id)->count())->toBe(2);
});

test('ProvisionPDService::supprimer removes all PD transactions', function () {
    $provision = Provision::factory()->create([
        'association_id' => $this->association->id,
        'exercice' => 2025,
        'type' => 'recette',
        'montant' => 500.00,
        'libelle' => 'PCA test',
        'date' => '2026-08-31',
        'saisi_par' => $this->user->id,
    ]);

    $service = app(ProvisionPDService::class);
    $service->generer($provision);
    expect(Transaction::where('provision_id', $provision->id)->count())->toBe(2);

    $service->supprimer($provision);
    expect(Transaction::where('provision_id', $provision->id)->count())->toBe(0);
});

test('ProvisionIndex::save creates PD transactions on new provision', function () {
    $compte = Compte::factory()->create([
        'association_id' => $this->association->id,
    ]);

    Livewire::test(ProvisionIndex::class)
        ->set('libelle', 'Test provision PD')
        ->set('compte_id', (string) $compte->id)
        ->set('type', 'depense')
        ->set('montant', '1200.50')
        ->call('save');

    $provision = Provision::where('libelle', 'Test provision PD')->first();
    expect($provision)->not->toBeNull();
    expect(Transaction::where('provision_id', $provision->id)->count())->toBe(2);
});

test('ProvisionIndex::delete removes PD transactions', function () {
    $provision = Provision::factory()->create([
        'association_id' => $this->association->id,
        'exercice' => app(ExerciceService::class)->current(),
        'type' => 'depense',
        'montant' => 500.00,
        'libelle' => 'To delete',
        'date' => '2026-08-31',
        'saisi_par' => $this->user->id,
    ]);

    app(ProvisionPDService::class)->generer($provision);
    expect(Transaction::where('provision_id', $provision->id)->count())->toBe(2);

    Livewire::test(ProvisionIndex::class)
        ->call('delete', $provision->id);

    expect(Transaction::where('provision_id', $provision->id)->count())->toBe(0);
    expect(Provision::find($provision->id))->toBeNull();
});
