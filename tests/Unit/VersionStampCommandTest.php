<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;

// config/version.php est versionné : ces tests le suppriment ou le réécrivent pour simuler
// son absence. On garde son contenu pour le remettre après chaque test — sinon chaque
// passage de la suite supprime le fichier de la copie de travail, ou en change la date.
beforeEach(function (): void {
    $this->versionOrigine = file_exists(config_path('version.php'))
        ? file_get_contents(config_path('version.php'))
        : null;
});

afterEach(function (): void {
    if ($this->versionOrigine !== null) {
        file_put_contents(config_path('version.php'), $this->versionOrigine);
    } else {
        @unlink(config_path('version.php'));
    }
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
