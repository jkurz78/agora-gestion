<?php

declare(strict_types=1);

use App\Models\Association;
use App\Models\Newsletter\SubscriptionRequest;
use App\Models\Tiers;
use App\Models\User;
use App\Services\TiersQuickViewService;
use App\Tenant\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->association = Association::factory()->create();
    $this->user = User::factory()->create(['peut_voir_donnees_sensibles' => false]);
    $this->user->associations()->attach($this->association->id, ['role' => 'admin', 'joined_at' => now()]);
    TenantContext::boot($this->association);
    $this->actingAs($this->user);
});

it('TiersQuickViewService::getSummary expose is_abonne_newsletter true si abonné', function (): void {
    $tiers = Tiers::factory()->create(['email' => 'abonne@x.fr']);
    SubscriptionRequest::factory()->importee($tiers->id)->create(['email' => 'abonne@x.fr']);

    $summary = app(TiersQuickViewService::class)->getSummary($tiers, 2025);

    expect($summary['contact']['is_abonne_newsletter'])->toBeTrue();
});

it('TiersQuickViewService::getSummary expose is_abonne_newsletter false si jamais abonné', function (): void {
    $tiers = Tiers::factory()->create();

    $summary = app(TiersQuickViewService::class)->getSummary($tiers, 2025);

    expect($summary['contact']['is_abonne_newsletter'])->toBeFalse();
});

it('TiersQuickViewService::getSummary expose is_abonne_newsletter false si désinscrit', function (): void {
    $tiers = Tiers::factory()->create(['email' => 'desabonne@x.fr']);
    SubscriptionRequest::factory()->desinscriptionAtraiter($tiers->id)->create(['email' => 'desabonne@x.fr']);

    $summary = app(TiersQuickViewService::class)->getSummary($tiers, 2025);

    expect($summary['contact']['is_abonne_newsletter'])->toBeFalse();
});

it('TiersQuickViewService::getSummary expose is_optout selon la valeur de email_optout sur le Tiers', function (): void {
    $tiersOpt = Tiers::factory()->create(['email_optout' => true]);
    $tiersOk = Tiers::factory()->create(['email_optout' => false]);

    $summOpt = app(TiersQuickViewService::class)->getSummary($tiersOpt, 2025);
    $summOk = app(TiersQuickViewService::class)->getSummary($tiersOk, 2025);

    expect($summOpt['contact']['is_optout'])->toBeTrue();
    expect($summOk['contact']['is_optout'])->toBeFalse();
});
