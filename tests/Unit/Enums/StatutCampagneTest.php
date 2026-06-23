<?php

declare(strict_types=1);

use App\Enums\StatutCampagne;

it('autorise les bonnes transitions', function (): void {
    expect(StatutCampagne::Brouillon->peutOuvrir())->toBeTrue();
    expect(StatutCampagne::Ouverte->peutOuvrir())->toBeFalse();
    expect(StatutCampagne::Ouverte->peutCloturer())->toBeTrue();
    expect(StatutCampagne::Ouverte->accepteReponses())->toBeTrue();
    expect(StatutCampagne::Cloturee->accepteReponses())->toBeFalse();
});
