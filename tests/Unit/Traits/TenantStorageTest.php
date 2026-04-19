<?php

declare(strict_types=1);

use App\Traits\TenantStorage;

class FakeTenantModel
{
    use TenantStorage;

    public int $association_id = 42;
}

it('préfixe le chemin avec associations/{association_id}/', function () {
    $model = new FakeTenantModel;
    expect($model->storagePath('branding/logo.png'))->toBe('associations/42/branding/logo.png');
});

it('rejette les suffixes avec traversal', function () {
    $model = new FakeTenantModel;
    expect(fn () => $model->storagePath('../44/logo.png'))->toThrow(InvalidArgumentException::class);
});
