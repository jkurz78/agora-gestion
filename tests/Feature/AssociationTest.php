<?php
// tests/Feature/AssociationTest.php

use App\Models\Association;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
});

it('creates association row with id=1 when none exists', function () {
    Association::updateOrCreate(['id' => 1], [
        'nom' => 'Mon Association',
        'adresse' => '1 rue de Paris',
        'code_postal' => '75001',
        'ville' => 'Paris',
        'email' => 'contact@asso.fr',
        'telephone' => '0123456789',
    ]);

    $this->assertDatabaseHas('association', [
        'id' => 1,
        'nom' => 'Mon Association',
    ]);
});

it('updates existing association without creating duplicate', function () {
    Association::updateOrCreate(['id' => 1], ['nom' => 'V1']);
    Association::updateOrCreate(['id' => 1], ['nom' => 'V2']);

    expect(Association::count())->toBe(1)
        ->and(Association::find(1)->nom)->toBe('V2');
});
