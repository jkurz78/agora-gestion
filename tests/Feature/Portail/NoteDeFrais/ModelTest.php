<?php

declare(strict_types=1);

use App\Enums\StatutNoteDeFrais;
use App\Enums\StatutReglement;
use App\Models\Association;
use App\Models\NoteDeFrais;
use App\Models\NoteDeFraisLigne;
use App\Models\TenantModel;
use App\Models\Transaction;
use App\Tenant\TenantContext;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Schema;

// Tests 6 (TenantScope fail-closed) override the global bootstrap with their own beforeEach.
// All other tests rely on the global Pest.php bootstrap (Association + TenantContext booted).

// ---------------------------------------------------------------------------
// 1. Table notes_de_frais
// ---------------------------------------------------------------------------

it('migration: table notes_de_frais exists with expected columns', function () {
    expect(Schema::hasTable('notes_de_frais'))->toBeTrue();

    $columns = [
        'id',
        'association_id',
        'tiers_id',
        'date',
        'libelle',
        'statut',
        'motif_rejet',
        'transaction_id',
        'submitted_at',
        'validee_at',
        'deleted_at',
        'created_at',
        'updated_at',
    ];

    expect(Schema::hasColumns('notes_de_frais', $columns))->toBeTrue();
});

it('migration: notes_de_frais has composite index on (association_id, tiers_id)', function () {
    $indexes = Schema::getIndexes('notes_de_frais');

    $composite = collect($indexes)->first(function ($index) {
        $cols = $index['columns'];

        return in_array('association_id', $cols, true) && in_array('tiers_id', $cols, true);
    });

    expect($composite)->not->toBeNull(
        'Expected a composite index on (association_id, tiers_id) in notes_de_frais but none found.'
    );
});

// ---------------------------------------------------------------------------
// 2. Table notes_de_frais_lignes
// ---------------------------------------------------------------------------

it('migration: table notes_de_frais_lignes exists with expected columns', function () {
    expect(Schema::hasTable('notes_de_frais_lignes'))->toBeTrue();

    $columns = [
        'id',
        'note_de_frais_id',
        'sous_categorie_id',
        'operation_id',
        'seance',
        'libelle',
        'montant',
        'piece_jointe_path',
        'created_at',
        'updated_at',
    ];

    expect(Schema::hasColumns('notes_de_frais_lignes', $columns))->toBeTrue();
});

// ---------------------------------------------------------------------------
// 3. Enum StatutNoteDeFrais
// ---------------------------------------------------------------------------

it('enum: StatutNoteDeFrais has 5 cases', function () {
    $cases = StatutNoteDeFrais::cases();

    expect($cases)->toHaveCount(5);

    $values = array_map(fn ($c) => $c->value, $cases);

    expect($values)->toContain('brouillon')
        ->toContain('soumise')
        ->toContain('rejetee')
        ->toContain('validee')
        ->toContain('payee');
});

it('enum: StatutNoteDeFrais::label() returns French labels', function () {
    expect(StatutNoteDeFrais::Brouillon->label())->toBeString()->not->toBeEmpty()
        ->and(StatutNoteDeFrais::Soumise->label())->toBeString()->not->toBeEmpty()
        ->and(StatutNoteDeFrais::Rejetee->label())->toBeString()->not->toBeEmpty()
        ->and(StatutNoteDeFrais::Validee->label())->toBeString()->not->toBeEmpty()
        ->and(StatutNoteDeFrais::Payee->label())->toBeString()->not->toBeEmpty();
});

// ---------------------------------------------------------------------------
// 4. Model NoteDeFrais
// ---------------------------------------------------------------------------

it('model: NoteDeFrais extends TenantModel', function () {
    expect(NoteDeFrais::class)->toExtend(TenantModel::class);
});

it('model: NoteDeFrais uses SoftDeletes', function () {
    expect(in_array(
        SoftDeletes::class,
        class_uses_recursive(NoteDeFrais::class),
        true
    ))->toBeTrue();
});

it('model: NoteDeFrais uses HasFactory', function () {
    expect(in_array(
        HasFactory::class,
        class_uses_recursive(NoteDeFrais::class),
        true
    ))->toBeTrue();
});

it('model: NoteDeFrais fillable contains required fields', function () {
    $ndf = new NoteDeFrais;
    $fillable = $ndf->getFillable();

    foreach ([
        'association_id', 'tiers_id', 'date', 'libelle', 'statut',
        'motif_rejet', 'transaction_id', 'submitted_at', 'validee_at',
    ] as $field) {
        expect(in_array($field, $fillable, true))->toBeTrue("Field '{$field}' missing from fillable");
    }
});

it('model: NoteDeFrais has correct casts', function () {
    $ndf = new NoteDeFrais;
    $casts = $ndf->getCasts();

    expect($casts)->toHaveKey('date')
        ->and($casts['date'])->toBe('date')
        ->and($casts)->toHaveKey('submitted_at')
        ->and($casts['submitted_at'])->toBe('datetime')
        ->and($casts)->toHaveKey('validee_at')
        ->and($casts['validee_at'])->toBe('datetime');
});

it('model: NoteDeFrais has tiers() BelongsTo relation', function () {
    $ndf = new NoteDeFrais;

    expect($ndf->tiers())->toBeInstanceOf(BelongsTo::class);
});

it('model: NoteDeFrais has transaction() BelongsTo relation', function () {
    $ndf = new NoteDeFrais;

    expect($ndf->transaction())->toBeInstanceOf(BelongsTo::class);
});

it('model: NoteDeFrais has lignes() HasMany relation', function () {
    $ndf = new NoteDeFrais;

    expect($ndf->lignes())->toBeInstanceOf(HasMany::class);
});

// ---------------------------------------------------------------------------
// 5. Model NoteDeFraisLigne
// ---------------------------------------------------------------------------

it('model: NoteDeFraisLigne does not extend TenantModel', function () {
    expect(NoteDeFraisLigne::class)->not->toExtend(TenantModel::class);
});

it('model: NoteDeFraisLigne has correct casts', function () {
    $ligne = new NoteDeFraisLigne;
    $casts = $ligne->getCasts();

    expect($casts)->toHaveKey('montant');
});

it('model: NoteDeFraisLigne fillable contains required fields', function () {
    $ligne = new NoteDeFraisLigne;
    $fillable = $ligne->getFillable();

    foreach ([
        'note_de_frais_id', 'sous_categorie_id', 'operation_id', 'seance',
        'libelle', 'montant', 'piece_jointe_path',
    ] as $field) {
        expect(in_array($field, $fillable, true))->toBeTrue("Field '{$field}' missing from fillable");
    }
});

it('model: NoteDeFraisLigne has noteDeFrais() BelongsTo relation', function () {
    $ligne = new NoteDeFraisLigne;

    expect($ligne->noteDeFrais())->toBeInstanceOf(BelongsTo::class);
});

it('model: NoteDeFraisLigne has sousCategorie() BelongsTo relation', function () {
    $ligne = new NoteDeFraisLigne;

    expect($ligne->sousCategorie())->toBeInstanceOf(BelongsTo::class);
});

it('model: NoteDeFraisLigne has operation() BelongsTo relation', function () {
    $ligne = new NoteDeFraisLigne;

    expect($ligne->operation())->toBeInstanceOf(BelongsTo::class);
});

it('model: NoteDeFraisLigne casts seance as integer', function () {
    $ligne = new NoteDeFraisLigne;
    $casts = $ligne->getCasts();

    expect($casts)->toHaveKey('seance')
        ->and($casts['seance'])->toBe('integer');
});

// ---------------------------------------------------------------------------
// 6. TenantScope fail-closed
// ---------------------------------------------------------------------------

it('tenant: NoteDeFrais::count() === 0 when TenantContext not booted (fail-closed)', function () {
    TenantContext::clear();

    expect(TenantContext::hasBooted())->toBeFalse();

    // Create a NDF by booting temporarily
    TenantContext::boot(Association::factory()->create());
    NoteDeFrais::factory()->create();
    TenantContext::clear();

    // Now without context — fail-closed
    expect(NoteDeFrais::count())->toBe(0);
})->beforeEach(fn () => TenantContext::clear());

// ---------------------------------------------------------------------------
// 7. SoftDelete
// ---------------------------------------------------------------------------

it('softdelete: deleted_at is set after delete() and excluded from count()', function () {
    $ndf = NoteDeFrais::factory()->create();

    expect(NoteDeFrais::count())->toBe(1);

    $ndf->delete();

    expect(NoteDeFrais::count())->toBe(0)
        ->and(NoteDeFrais::withTrashed()->count())->toBe(1)
        ->and(NoteDeFrais::find($ndf->id))->toBeNull() // normal scope excludes trashed
        ->and(NoteDeFrais::withTrashed()->find($ndf->id)?->deleted_at)->not->toBeNull();
});

// ---------------------------------------------------------------------------
// 8. Accessor statut — payee dérivé de Transaction
// ---------------------------------------------------------------------------

it('accessor: statut returns Payee when linked transaction statut_reglement is Pointe', function () {
    $transaction = Transaction::factory()->create([
        'statut_reglement' => StatutReglement::Pointe,
    ]);

    $ndf = NoteDeFrais::factory()->create([
        'statut' => 'validee',
        'transaction_id' => $transaction->id,
    ]);

    expect($ndf->statut)->toBe(StatutNoteDeFrais::Payee);
});

it('accessor: statut returns Validee when linked transaction is not Pointe', function () {
    $transaction = Transaction::factory()->create([
        'statut_reglement' => StatutReglement::EnAttente,
    ]);

    $ndf = NoteDeFrais::factory()->create([
        'statut' => 'validee',
        'transaction_id' => $transaction->id,
    ]);

    expect($ndf->statut)->toBe(StatutNoteDeFrais::Validee);
});

it('accessor: statut returns Validee when no transaction linked', function () {
    $ndf = NoteDeFrais::factory()->create([
        'statut' => 'validee',
        'transaction_id' => null,
    ]);

    expect($ndf->statut)->toBe(StatutNoteDeFrais::Validee);
});
