<?php

declare(strict_types=1);

use App\Livewire\AdherentList;
use App\Models\Adhesion;
use App\Models\Tiers;
use App\Models\User;
use App\Tenant\TenantContext;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

/*
 * Écran Adhérents (spec 2026-10-02, D3 et D4, AC-6 et AC-7).
 *
 * Deux défauts indépendants y faussaient une liste de personnes :
 *  - la « dernière adhésion » triait par exercice en premier : NULL en dernier ;
 *  - les filtres combinaient l'exercice SÉLECTIONNÉ et la date d'AUJOURD'HUI.
 */

beforeEach(function (): void {
    $this->user = User::factory()->create();
    $this->user->associations()->attach(TenantContext::currentId(), ['role' => 'admin', 'joined_at' => now()]);
    session(['exercice_actif' => 2025]);
});

afterEach(function (): void {
    session()->forget('exercice_actif');
});

function adherentAvec(string $nom, array ...$adhesions): Tiers
{
    $tiers = Tiers::factory()->create(['nom' => $nom]);
    foreach ($adhesions as $attributs) {
        Adhesion::factory()->create(array_merge(['tiers_id' => $tiers->id], $attributs));
    }

    return $tiers;
}

/** @return list<string> noms des tiers listés par le filtre, triés */
function adherentsListes(User $user, string $filtre, ?int $exercice = null): array
{
    if ($exercice !== null) {
        session(['exercice_actif' => $exercice]);
    }

    $noms = Livewire::actingAs($user)
        ->test(AdherentList::class)
        ->set('filtre', $filtre)
        ->viewData('membres')
        ->pluck('nom')
        ->map(fn (string $nom): string => mb_strtoupper($nom))
        ->all();

    sort($noms);

    return $noms;
}

function adherentDerniereAdhesion(User $user, Tiers $tiers): Adhesion
{
    return Livewire::actingAs($user)
        ->test(AdherentList::class)
        ->set('filtre', 'tous')
        ->viewData('membres')
        ->firstWhere('id', $tiers->id)
        ->derniereAdhesion;
}

// ─── AC-6 / D4 : la « dernière adhésion » ────────────────────────────────────

it('AC-6 · la dernière adhésion d\'un tiers à 2025-2026 et 2026-2027 est celle de 2026-2027 (cas Moniotte, exercice nul)', function (): void {
    $moniotte = adherentAvec(
        'Moniotte',
        ['exercice' => 2025, 'date_debut' => '2025-09-01', 'date_fin' => '2026-08-31'],
        // L'état de production avant le correctif : exercice jamais renseigné.
        ['exercice' => null, 'date_debut' => '2026-09-01', 'date_fin' => '2027-08-31'],
    );

    $derniere = adherentDerniereAdhesion($this->user, $moniotte);

    expect($derniere->date_debut->toDateString())->toBe('2026-09-01')
        ->and($derniere->date_fin->toDateString())->toBe('2027-08-31');
});

it('AC-6 · la dernière adhésion est la même une fois l\'exercice renseigné', function (): void {
    $moniotte = adherentAvec(
        'Moniotte',
        ['exercice' => 2026, 'date_debut' => '2026-09-01', 'date_fin' => '2027-08-31'],
        ['exercice' => 2025, 'date_debut' => '2025-09-01', 'date_fin' => '2026-08-31'],
    );

    expect(adherentDerniereAdhesion($this->user, $moniotte)->exercice)->toBe(2026);
});

it('D4 · une adhésion illimitée, qui n\'a pas de fin, passe avant une adhésion datée', function (): void {
    $fondateur = adherentAvec(
        'Fondateur',
        ['exercice' => 2020, 'mode' => 'illimite', 'date_debut' => '2020-10-01', 'date_fin' => null],
        ['exercice' => 2026, 'date_debut' => '2026-09-01', 'date_fin' => '2027-08-31'],
    );

    expect(adherentDerniereAdhesion($this->user, $fondateur)->mode)->toBe('illimite');
});

it('D4 · à date de fin égale, l\'exercice départage', function (): void {
    $tiers = adherentAvec(
        'Egalite',
        ['exercice' => 2024, 'date_debut' => '2026-01-01', 'date_fin' => '2026-12-31'],
        ['exercice' => 2025, 'date_debut' => '2026-03-01', 'date_fin' => '2026-12-31'],
    );

    expect(adherentDerniereAdhesion($this->user, $tiers)->exercice)->toBe(2025);
});

it('D4 · à date de fin et exercice égaux, l\'identifiant le plus récent l\'emporte', function (): void {
    // Deux adhésions de même exercice et de même fin ne se distinguent que par leur début :
    // la clé unique (tiers, exercice, date_debut) les autorise.
    $tiers = adherentAvec(
        'Ex-aequo',
        ['exercice' => 2025, 'date_debut' => '2025-09-01', 'date_fin' => '2026-08-31', 'notes' => 'ancienne'],
        ['exercice' => 2025, 'date_debut' => '2025-12-01', 'date_fin' => '2026-08-31', 'notes' => 'recente'],
    );

    expect(adherentDerniereAdhesion($this->user, $tiers)->notes)->toBe('recente');
});

it('D4 · des adhésions sans dates (offertes anciennes) restent départagées par l\'exercice', function (): void {
    $tiers = adherentAvec(
        'SansDates',
        ['exercice' => 2023],
        ['exercice' => 2025],
        ['exercice' => 2024],
    );

    expect(adherentDerniereAdhesion($this->user, $tiers)->exercice)->toBe(2025);
});

// ─── AC-7 / D3 : le filtre « à jour » a une seule référence de temps ─────────

/**
 * La photographie de production du 2026-10-02 : on est en octobre 2026, le
 * sélecteur est sur 2025-2026.
 */
function adherentsPhotoProduction(): void
{
    Carbon::setTestNow('2026-10-02 10:00:00');

    // Membre de 2025-2026 ; exercice jamais renseigné, dates échues depuis un mois.
    adherentAvec('Notz', ['exercice' => null, 'date_debut' => '2025-09-01', 'date_fin' => '2026-08-31']);
    // Membres de 2026-2027 uniquement : l'un à exercice nul, l'autre déjà repris.
    adherentAvec('Kohl', ['exercice' => null, 'date_debut' => '2026-09-01', 'date_fin' => '2027-08-31']);
    adherentAvec('SalinBeneteau', ['exercice' => 2026, 'date_debut' => '2026-09-01', 'date_fin' => '2027-08-31']);
    // Membre de 2025-2026, exercice renseigné.
    adherentAvec('Dupont', ['exercice' => 2025, 'date_debut' => '2025-09-01', 'date_fin' => '2026-08-31']);
    // Sans adhésion : jamais listé.
    Tiers::factory()->create(['nom' => 'Absent']);
}

it('AC-7 · sélecteur sur 2025-2026 : « à jour » retient Notz et exclut Kohl et Salin Beneteau', function (): void {
    adherentsPhotoProduction();

    expect(adherentsListes($this->user, 'a_jour', 2025))->toBe(['DUPONT', 'NOTZ']);
});

it('AC-7 · sélecteur sur 2026-2027 : « à jour » retient Kohl et Salin Beneteau et exclut Notz', function (): void {
    adherentsPhotoProduction();

    expect(adherentsListes($this->user, 'a_jour', 2026))->toBe(['KOHL', 'SALINBENETEAU']);
});

it('D3 · le résultat ne dépend pas de la date du jour, seulement de l\'exercice sélectionné', function (): void {
    adherentsPhotoProduction();

    $resultats = [];
    foreach (['2025-03-01', '2026-01-15', '2026-10-02', '2028-06-30'] as $aujourdhui) {
        Carbon::setTestNow("{$aujourdhui} 10:00:00");
        $resultats[$aujourdhui] = [
            'a_jour_2025' => adherentsListes($this->user, 'a_jour', 2025),
            'a_jour_2026' => adherentsListes($this->user, 'a_jour', 2026),
            'en_retard_2026' => adherentsListes($this->user, 'en_retard', 2026),
        ];
    }

    expect(array_unique(array_map('serialize', $resultats)))->toHaveCount(1);
});

it('D3 · une adhésion en durée à cheval sur deux exercices est à jour pour les deux', function (): void {
    adherentAvec('Cheval', ['exercice' => 2025, 'date_debut' => '2026-03-01', 'date_fin' => '2027-02-28']);

    expect(adherentsListes($this->user, 'a_jour', 2024))->toBe([])
        ->and(adherentsListes($this->user, 'a_jour', 2025))->toBe(['CHEVAL'])
        ->and(adherentsListes($this->user, 'a_jour', 2026))->toBe(['CHEVAL'])
        ->and(adherentsListes($this->user, 'a_jour', 2027))->toBe([]);
});

it('D3 · une adhésion échue avant le début de l\'exercice sélectionné n\'est pas à jour', function (): void {
    adherentAvec('Echue', ['exercice' => 2023, 'date_debut' => '2024-01-15', 'date_fin' => '2025-01-14']);

    expect(adherentsListes($this->user, 'a_jour', 2025))->toBe([]);
});

it('D3 · une adhésion illimitée est à jour à partir de son début, pas avant', function (): void {
    adherentAvec('Permanent', ['exercice' => 2026, 'mode' => 'illimite', 'date_debut' => '2026-10-01', 'date_fin' => null]);
    adherentAvec('PermanentSansDate', ['exercice' => 2020, 'mode' => 'illimite', 'date_debut' => null, 'date_fin' => null]);

    expect(adherentsListes($this->user, 'a_jour', 2025))->toBe(['PERMANENTSANSDATE'])
        ->and(adherentsListes($this->user, 'a_jour', 2026))->toBe(['PERMANENT', 'PERMANENTSANSDATE']);
});

it('D3 · l\'exercice renseigné suffit, même sans dates', function (): void {
    adherentAvec('Offerte', ['exercice' => 2025, 'date_debut' => null, 'date_fin' => null]);

    expect(adherentsListes($this->user, 'a_jour', 2025))->toBe(['OFFERTE'])
        ->and(adherentsListes($this->user, 'a_jour', 2026))->toBe([]);
});

// ─── en_retard : même référence, même définition de « à jour » ───────────────

it('D3 · en_retard : à jour pour l\'exercice précédent, pas pour l\'exercice sélectionné', function (): void {
    adherentsPhotoProduction();

    // Sélecteur 2026 : Notz et Dupont étaient à jour en 2025 et ne le sont plus en 2026.
    expect(adherentsListes($this->user, 'en_retard', 2026))->toBe(['DUPONT', 'NOTZ'])
        // Sélecteur 2025 : personne n'était membre de 2024.
        ->and(adherentsListes($this->user, 'en_retard', 2025))->toBe([]);
});

it('D3 · en_retard n\'inclut jamais un tiers déjà à jour pour l\'exercice sélectionné', function (): void {
    adherentAvec('Renouvele',
        ['exercice' => 2024, 'date_debut' => '2024-09-01', 'date_fin' => '2025-08-31'],
        ['exercice' => 2025, 'date_debut' => '2025-09-01', 'date_fin' => '2026-08-31'],
    );
    adherentAvec('Parti', ['exercice' => 2024, 'date_debut' => '2024-09-01', 'date_fin' => '2025-08-31']);

    expect(adherentsListes($this->user, 'en_retard', 2025))->toBe(['PARTI'])
        ->and(adherentsListes($this->user, 'a_jour', 2025))->toBe(['RENOUVELE']);
});

it('D3 · en_retard reconnaît une adhésion en durée par ses dates, exercice nul compris', function (): void {
    adherentAvec('DureeEchue', ['exercice' => null, 'date_debut' => '2024-10-01', 'date_fin' => '2025-08-31']);

    expect(adherentsListes($this->user, 'en_retard', 2025))->toBe(['DUREEECHUE']);
});
