<?php

declare(strict_types=1);

use App\Models\User;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

it('requires authentication for export', function () {
    auth()->logout();
    $this->get('/compta/rapports/export/compte-resultat/xlsx')
        ->assertRedirect(route('login'));
});

it('rejects invalid rapport name', function () {
    $this->get('/compta/rapports/export/invalid-report/xlsx')
        ->assertNotFound();
});

it('rejects invalid format', function () {
    $this->get('/compta/rapports/export/compte-resultat/csv')
        ->assertNotFound();
});

it('rejects pdf format for analyse reports', function () {
    $this->get('/compta/rapports/export/analyse-financier/pdf')
        ->assertNotFound();
});

it('exports compte-resultat as xlsx', function () {
    $this->get('/compta/rapports/export/compte-resultat/xlsx')
        ->assertOk()
        ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
});

it('exports compte-resultat as pdf', function () {
    $this->get('/compta/rapports/export/compte-resultat/pdf')
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');
});

it('exports operations as xlsx with filters', function () {
    $this->get('/compta/rapports/export/operations/xlsx?ops[]=1&seances=1&tiers=1')
        ->assertOk()
        ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
});

it('exports operations as pdf', function () {
    $this->get('/compta/rapports/export/operations/pdf')
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');
});

it('exports flux-tresorerie as xlsx', function () {
    $this->get('/compta/rapports/export/flux-tresorerie/xlsx')
        ->assertOk()
        ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
});

it('exports flux-tresorerie as pdf', function () {
    $this->get('/compta/rapports/export/flux-tresorerie/pdf')
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');
});

it('exports analyse-financier as xlsx', function () {
    $this->get('/compta/rapports/export/analyse-financier/xlsx')
        ->assertOk()
        ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
});

it('exports analyse-participants as xlsx', function () {
    $this->get('/compta/rapports/export/analyse-participants/xlsx')
        ->assertOk()
        ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
});
