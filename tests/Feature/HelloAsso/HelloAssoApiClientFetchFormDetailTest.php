<?php

declare(strict_types=1);

use App\Models\HelloAssoParametres;
use App\Services\HelloAssoApiClient;
use App\Tenant\TenantContext;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    $this->parametres = HelloAssoParametres::create([
        'association_id' => TenantContext::currentId(),
        'client_id' => 'cid',
        'client_secret' => 'csecret',
        'organisation_slug' => 'mon-asso',
        'environnement' => 'sandbox',
    ]);
});

it('retourne le détail du form avec ses tiers', function (): void {
    Http::fake([
        '*api.helloasso-sandbox.com/oauth2/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
        '*api.helloasso-sandbox.com/v5/organizations/mon-asso/forms/Membership/cotisation-2025' => Http::response([
            'formSlug' => 'cotisation-2025',
            'formType' => 'Membership',
            'validityType' => 'MovingYear',
            'tiers' => [
                ['id' => 1, 'label' => 'Adulte', 'price' => 3000, 'isEligibleTaxReceipt' => false],
                ['id' => 2, 'label' => 'Étudiant', 'price' => 1500, 'isEligibleTaxReceipt' => false],
            ],
        ]),
    ]);

    $client = new HelloAssoApiClient($this->parametres);
    $form = $client->fetchFormDetail('Membership', 'cotisation-2025');

    expect($form['formSlug'])->toBe('cotisation-2025');
    expect($form['validityType'])->toBe('MovingYear');
    expect($form['tiers'])->toHaveCount(2);
    expect($form['tiers'][0]['label'])->toBe('Adulte');
});
