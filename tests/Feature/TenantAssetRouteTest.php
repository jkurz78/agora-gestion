<?php

declare(strict_types=1);

use App\Models\Association;
use App\Models\User;
use App\Support\TenantAsset;
use App\Tenant\TenantContext;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->association = Association::factory()->create();
    $this->user = User::factory()->create();
    $this->user->associations()->attach($this->association->id, ['role' => 'admin', 'joined_at' => now()]);
    TenantContext::boot($this->association);
    session(['current_association_id' => $this->association->id]);
    $this->actingAs($this->user);
});

afterEach(fn () => TenantContext::clear());

it('sert un fichier du tenant courant via URL signée', function () {
    $path = 'associations/'.$this->association->id.'/branding/logo.png';
    Storage::disk('local')->put($path, 'FAKEPNG');

    $response = $this->get(TenantAsset::url($path));

    $response->assertOk();
    expect($response->streamedContent())->toBe('FAKEPNG');
});

it('refuse un fichier d\'un autre tenant même avec signature valide', function () {
    $autre = Association::factory()->create();
    $path = 'associations/'.$autre->id.'/branding/logo.png';
    Storage::disk('local')->put($path, 'SECRET');

    $response = $this->get(TenantAsset::url($path));

    $response->assertForbidden();
});

it('refuse une signature invalide', function () {
    $path = 'associations/'.$this->association->id.'/branding/logo.png';
    Storage::disk('local')->put($path, 'FAKE');

    $response = $this->get('/tenant-assets/'.$path.'?signature=bogus');

    $response->assertForbidden();
});

it('refuse un path traversal', function () {
    $response = $this->get(TenantAsset::url('associations/1/../2/logo.png'));

    $response->assertForbidden();
});
