<?php

declare(strict_types=1);

use App\Models\User;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

it('responds 200 on compte-resultat page', function () {
    $this->get('/compta/rapports/compte-resultat')->assertOk();
});

it('responds 200 on operations page', function () {
    $this->get('/compta/rapports/operations')->assertOk();
});

it('responds 200 on analyse page', function () {
    $this->get('/compta/rapports/analyse')->assertOk();
});

it('redirects old /compta/rapports to compte-resultat', function () {
    $this->get('/compta/rapports')
        ->assertRedirect('/compta/rapports/compte-resultat');
});

it('redirects legacy /rapports to compte-resultat', function () {
    $this->get('/rapports')
        ->assertRedirect('/compta/rapports/compte-resultat');
});
