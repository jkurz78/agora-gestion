<?php

declare(strict_types=1);

use App\Support\TenantAsset;

it('produit une URL signée vers la route tenant-assets', function () {
    $url = TenantAsset::url('associations/1/branding/logo.png');
    expect($url)->toContain('/tenant-assets/');
    expect($url)->toContain('signature=');
});

it('accepte une TTL optionnelle', function () {
    $url = TenantAsset::url('associations/1/branding/logo.png', expiresInMinutes: 5);
    expect($url)->toContain('expires=');
});
