<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;

afterEach(function (): void {
    @unlink(config_path('version.php'));
});

it('crée config/version.php avec les clés tag et date', function (): void {
    @unlink(config_path('version.php'));

    Artisan::call('app:version-stamp');

    expect(file_exists(config_path('version.php')))->toBeTrue();

    $version = require config_path('version.php');

    expect($version)->toBeArray()
        ->toHaveKey('tag')
        ->toHaveKey('date');

    expect($version['tag'])->toBeString()->not->toBeEmpty();
    expect($version['date'])->toBeString()->not->toBeEmpty();
});

it('affiche un message de confirmation après stamping', function (): void {
    @unlink(config_path('version.php'));

    $exitCode = Artisan::call('app:version-stamp');

    expect($exitCode)->toBe(0);

    $output = Artisan::output();
    expect($output)->toContain('Version stamped:');
});
