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

// ── stripTrackingPixel + previewSwap (back-office preview ne doit pas compter
//    comme une ouverture destinataire) ────────────────────────────────────────

it('stripTrackingPixel retire le pixel de tracking', function (): void {
    $html = '<p>Bonjour</p><img src="https://app.test/t/abc123token.gif" width="1" height="1" alt="" style="display:none">';

    $result = EmailLogo::stripTrackingPixel($html);

    expect($result)->toBe('<p>Bonjour</p>');
});

it('stripTrackingPixel laisse les autres <img> intacts', function (): void {
    $html = '<p>Hello</p><img src="https://example.com/photo.jpg" alt="photo"><img src="cid:logo-asso">';

    $result = EmailLogo::stripTrackingPixel($html);

    expect($result)->toContain('<img src="https://example.com/photo.jpg"')
        ->and($result)->toContain('cid:logo-asso')
        ->and($result)->not->toContain('/t/');
});

it('stripTrackingPixel matche indépendamment de l\'ordre des attributs', function (): void {
    $html = '<img style="display:none" alt="" height="1" width="1" src="http://app.test/t/xyz.gif">';

    expect(EmailLogo::stripTrackingPixel($html))->toBe('');
});

it('previewSwap retire le pixel de tracking (back-office preview ≠ ouverture réelle)', function (): void {
    Storage::fake('local');
    $html = '<p>Coucou</p><img src="https://app.test/t/sometoken.gif" width="1" height="1" alt="" style="display:none">';

    $result = EmailLogo::previewSwap($html);

    expect($result)->toContain('Coucou')
        ->and($result)->not->toContain('/t/')
        ->and($result)->not->toContain('.gif');
});
