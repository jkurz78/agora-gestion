<?php

declare(strict_types=1);

use App\Models\Association;
use App\Models\NoteDeFrais;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Tenant\TenantContext;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    $this->asso = Association::factory()->create();
    TenantContext::boot($this->asso);
});

afterEach(function () {
    TenantContext::clear();
});

it('notes_de_frais has abandon_creance_propose column', function (): void {
    expect(Schema::hasColumn('notes_de_frais', 'abandon_creance_propose'))->toBeTrue();
});

it('notes_de_frais has don_transaction_id column', function (): void {
    expect(Schema::hasColumn('notes_de_frais', 'don_transaction_id'))->toBeTrue();
});

it('abandon_creance_propose defaults to false', function (): void {
    $tiers = Tiers::factory()->create(['association_id' => $this->asso->id]);

    $ndf = NoteDeFrais::factory()->create([
        'association_id' => $this->asso->id,
        'tiers_id' => $tiers->id,
    ]);

    expect((bool) $ndf->fresh()->abandon_creance_propose)->toBeFalse();
});

it('don_transaction_id FK is set null on transaction delete', function (): void {
    $tiers = Tiers::factory()->create(['association_id' => $this->asso->id]);
    $transaction = Transaction::factory()->create(['association_id' => $this->asso->id]);

    $ndf = NoteDeFrais::factory()->create([
        'association_id' => $this->asso->id,
        'tiers_id' => $tiers->id,
        'don_transaction_id' => $transaction->id,
    ]);

    expect($ndf->fresh()->don_transaction_id)->toBe((int) $transaction->id);

    $transaction->forceDelete();

    expect($ndf->fresh()->don_transaction_id)->toBeNull();
});
