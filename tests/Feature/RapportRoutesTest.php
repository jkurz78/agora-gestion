<?php

declare(strict_types=1);

use App\Models\User;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

it('responds 200 on compte-resultat page', function () {
    $this->get('/rapports/compte-resultat')->assertOk();
});

it('responds 200 on operations page', function () {
    $this->get('/rapports/operations')->assertOk();
});

it('responds 200 on analyse page', function () {
    $this->get('/rapports/analyse')->assertOk();
});

it('redirects /rapports to compte-resultat', function () {
    $this->get('/rapports')
        ->assertRedirect('/rapports/compte-resultat');
});

it('redirects legacy /compta/rapports to compte-resultat', function () {
    $this->get('/compta/rapports')
        ->assertRedirect('/rapports/compte-resultat');
});

it('redirects legacy /compta/rapports/compte-resultat to new URL', function () {
    $this->get('/compta/rapports/compte-resultat')
        ->assertRedirect('/rapports/compte-resultat');
});
