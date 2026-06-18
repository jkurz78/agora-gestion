<?php

declare(strict_types=1);

use App\Models\Association;
use App\Models\Compte;
use App\Models\User;
use App\Services\Compta\Migrations\SystemeSeeder;
use App\Tenant\TenantContext;

beforeEach(function () {
    $this->association = Association::factory()->create();
    $this->user = User::factory()->create();
    $this->user->associations()->attach($this->association->id, ['role' => 'admin', 'joined_at' => now()]);
    TenantContext::boot($this->association);
    session(['current_association_id' => $this->association->id]);
    $this->actingAs($this->user);

    SystemeSeeder::seed();
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
