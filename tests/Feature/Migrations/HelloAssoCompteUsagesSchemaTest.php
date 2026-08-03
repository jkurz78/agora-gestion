<?php

declare(strict_types=1);

use App\Enums\UsageComptable;
use App\Models\Compte;
use Illuminate\Support\Facades\Schema;

it('uses the final account usage table', function (): void {
    expect(Schema::hasTable('usages_comptes'))->toBeTrue();
});

it('account factory creates without usage by default', function (): void {
    $compte = Compte::factory()->create(['classe' => 7]);

    expect($compte->hasUsage(UsageComptable::Inscription))->toBeFalse();
});

it('factory pourInscriptions creates an account usage row', function (): void {
    $compte = Compte::factory()->pourInscriptions()->create(['classe' => 7]);

    expect($compte->hasUsage(UsageComptable::Inscription))->toBeTrue();
});
