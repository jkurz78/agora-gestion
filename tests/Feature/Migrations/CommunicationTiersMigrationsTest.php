<?php

declare(strict_types=1);

use App\Models\CampagneEmail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('tiers table has email_optout column', function () {
    expect(Schema::hasColumn('tiers', 'email_optout'))->toBeTrue();
});

it('association table has email_from and email_from_name columns', function () {
    expect(Schema::hasColumn('association', 'email_from'))->toBeTrue();
    expect(Schema::hasColumn('association', 'email_from_name'))->toBeTrue();
});

it('campagnes_email.operation_id is nullable', function () {
    $campagne = CampagneEmail::create([
        'operation_id' => null,
        'objet' => 'Test sans opération',
        'corps' => 'Corps test',
        'nb_destinataires' => 0,
        'nb_erreurs' => 0,
        'envoye_par' => User::factory()->create()->id,
    ]);

    expect($campagne->id)->toBeInt()
        ->and($campagne->operation_id)->toBeNull();
});
