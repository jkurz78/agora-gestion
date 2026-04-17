<?php

declare(strict_types=1);

use App\Models\Association;
use App\Models\User;
use App\Tenant\TenantContext;

beforeEach(function () {
    $this->association = Association::factory()->create();
    $this->user = User::factory()->create();
    $this->user->associations()->attach($this->association->id, ['role' => 'admin', 'joined_at' => now()]);
    TenantContext::boot($this->association);
    session(['current_association_id' => $this->association->id]);
    $this->actingAs($this->user);
});

afterEach(function () {
    TenantContext::clear();
});

it('requires authentication for export', function () {
    auth()->logout();
    $this->get('/rapports/export/compte-resultat/xlsx')
        ->assertRedirect(route('login'));
});

it('rejects invalid rapport name', function () {
    $this->get('/rapports/export/invalid-report/xlsx')
        ->assertNotFound();
});

it('rejects invalid format', function () {
    $this->get('/rapports/export/compte-resultat/csv')
        ->assertNotFound();
});

it('rejects pdf format for analyse reports', function () {
    $this->get('/rapports/export/analyse-financier/pdf')
        ->assertNotFound();
});

it('exports compte-resultat as xlsx', function () {
    $this->get('/rapports/export/compte-resultat/xlsx')
        ->assertOk()
        ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
});

it('exports compte-resultat as pdf', function () {
    $this->get('/rapports/export/compte-resultat/pdf')
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');
});

it('exports operations as xlsx with filters', function () {
    $this->get('/rapports/export/operations/xlsx?ops[]=1&seances=1&tiers=1')
        ->assertOk()
        ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
});

it('exports operations as pdf', function () {
    $this->get('/rapports/export/operations/pdf')
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');
});

it('exports flux-tresorerie as xlsx', function () {
    $this->get('/rapports/export/flux-tresorerie/xlsx')
        ->assertOk()
        ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
});

it('exports flux-tresorerie as pdf', function () {
    $this->get('/rapports/export/flux-tresorerie/pdf')
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');
});

it('exports analyse-financier as xlsx', function () {
    $this->get('/rapports/export/analyse-financier/xlsx')
        ->assertOk()
        ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
});

it('exports analyse-participants as xlsx', function () {
    $this->get('/rapports/export/analyse-participants/xlsx')
        ->assertOk()
        ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
});
