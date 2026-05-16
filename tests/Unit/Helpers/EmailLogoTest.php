<?php

declare(strict_types=1);

use App\Helpers\EmailLogo;
use App\Tenant\TenantContext;
use Illuminate\Support\Facades\Storage;

/**
 * Unit tests for EmailLogo::buildCidImgTag() sizing.
 *
 * Bug 2: the CID <img> tag was generated with height:80px (too tall for
 * an email footer). The fix reduces it to max-height:40px + height="40"
 * so both CSS-aware and HTML-only email clients render a discreet logo.
 */
it('produit un tag {logo} avec une hauteur ≤ 40px quand un logo association existe', function (): void {
    Storage::fake('local');

    // The global beforeEach (Pest.php) already booted a TenantContext with a fresh
    // Association. We grab it and update logo_path so resolve() finds a file.
    $association = TenantContext::current();
    expect($association)->not->toBeNull();

    // Build the expected storage path (associations/{id}/branding/logo.png).
    $association->logo_path = 'logo.png';
    $association->save();

    $storagePath = 'associations/'.$association->id.'/branding/logo.png';
    Storage::disk('local')->put($storagePath, 'fake-image-bytes');

    $vars = EmailLogo::variables();

    $logoTag = $vars['{logo}'];

    expect($logoTag)
        ->toBeString()
        ->not->toBe('')
        ->toContain('max-height:40px')
        ->toContain('height="40"');
});

it('produit un tag {logo} sans height:80px', function (): void {
    Storage::fake('local');

    $association = TenantContext::current();
    expect($association)->not->toBeNull();

    $association->logo_path = 'logo.png';
    $association->save();

    $storagePath = 'associations/'.$association->id.'/branding/logo.png';
    Storage::disk('local')->put($storagePath, 'fake-image-bytes');

    $vars = EmailLogo::variables();

    expect($vars['{logo}'])
        ->not->toContain('height:80px');
});
