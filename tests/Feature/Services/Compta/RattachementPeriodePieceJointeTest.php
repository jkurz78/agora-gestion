<?php

declare(strict_types=1);

use App\Enums\StatutExercice;
use App\Exceptions\ExerciceCloturedException;
use App\Models\Association;
use App\Models\Exercice;
use App\Models\Provision;
use App\Models\User;
use App\Services\Compta\Migrations\SystemeSeeder;
use App\Services\Compta\RattachementExerciceService;
use App\Tenant\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| RattachementExerciceService : la période fermée et la pièce jointe d'un rattachement retiré
|--------------------------------------------------------------------------
|
| Deux ajouts de l'écran (Task 11) au service :
|
|  - motifDeRefus() juge la LIGNE ; motifPeriodeFermee() juge la PÉRIODE, sans verrou : l'écran la lit
|    pour prévenir (un seul bandeau, des boutons désactivés). L'autorité reste le service, qui refuse sous
|    verrou dans rattacher() et retirer() ;
|  - le retrait supprime le dossier de la pièce jointe, APRÈS le commit et au mieux : un échec du disque
|    est journalisé, il ne fait pas échouer un retrait déjà validé.
*/

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->user->associations()->attach(TenantContext::currentId(), ['role' => 'admin', 'joined_at' => now()]);
    $this->actingAs($this->user);

    SystemeSeeder::seed();
});

// ── motifPeriodeFermee() (D-2) ───────────────────────────────────────────────

describe('RattachementExerciceService : motifPeriodeFermee', function () {
    test('aucun exercice en base : aucun motif, un exercice absent n’est pas clôturé', function () {
        expect(app(RattachementExerciceService::class)->motifPeriodeFermee(2025))->toBeNull();
    });

    test('N et N+1 ouverts : aucun motif', function () {
        Exercice::create(['annee' => 2025, 'statut' => StatutExercice::Ouvert]);
        Exercice::create(['annee' => 2026, 'statut' => StatutExercice::Ouvert]);

        expect(app(RattachementExerciceService::class)->motifPeriodeFermee(2025))->toBeNull();
    });

    test('N+1 absent, N ouvert : aucun motif (l’extourne est générée même si N+1 n’existe pas encore)', function () {
        Exercice::create(['annee' => 2025, 'statut' => StatutExercice::Ouvert]);

        expect(app(RattachementExerciceService::class)->motifPeriodeFermee(2025))->toBeNull();
    });

    test('N clôturé : le motif dit que 2025-2026 est clôturé, et pourquoi N+1 compte aussi', function () {
        Exercice::create(['annee' => 2025, 'statut' => StatutExercice::Cloture]);
        Exercice::create(['annee' => 2026, 'statut' => StatutExercice::Ouvert]);

        $motif = app(RattachementExerciceService::class)->motifPeriodeFermee(2025);

        expect($motif)->toBeString();
        expect($motif)->toContain('L’exercice 2025-2026 est clôturé');
        // Il ne désigne pas l'exercice resté ouvert comme clôturé...
        expect($motif)->not->toContain('Les exercices');
        expect($motif)->not->toContain('L’exercice 2026-2027 est clôturé');
        // ... mais explique que les deux comptent : la dotation vit dans N, l'extourne dans N+1.
        expect($motif)->toContain('2025-2026')->toContain('2026-2027');
        expect($motif)->toContain('dans deux exercices');
    });

    test('N+1 clôturé, N ouvert : le motif désigne 2026-2027, celui où l’annulation s’écrit', function () {
        Exercice::create(['annee' => 2025, 'statut' => StatutExercice::Ouvert]);
        Exercice::create(['annee' => 2026, 'statut' => StatutExercice::Cloture]);

        $motif = app(RattachementExerciceService::class)->motifPeriodeFermee(2025);

        expect($motif)->toBeString();
        expect($motif)->toContain('L’exercice 2026-2027 est clôturé');
        expect($motif)->not->toContain('L’exercice 2025-2026 est clôturé');
        expect($motif)->not->toContain('Les exercices');
    });

    test('N et N+1 clôturés : le motif les désigne tous les deux, au pluriel', function () {
        Exercice::create(['annee' => 2025, 'statut' => StatutExercice::Cloture]);
        Exercice::create(['annee' => 2026, 'statut' => StatutExercice::Cloture]);

        $motif = app(RattachementExerciceService::class)->motifPeriodeFermee(2025);

        expect($motif)->toContain('Les exercices 2025-2026 et 2026-2027 sont clôturés');
    });

    test('seuls N et N+1 comptent : un exercice antérieur ou postérieur clôturé n’y change rien', function () {
        Exercice::create(['annee' => 2024, 'statut' => StatutExercice::Cloture]);
        Exercice::create(['annee' => 2025, 'statut' => StatutExercice::Ouvert]);
        Exercice::create(['annee' => 2026, 'statut' => StatutExercice::Ouvert]);
        Exercice::create(['annee' => 2027, 'statut' => StatutExercice::Cloture]);

        expect(app(RattachementExerciceService::class)->motifPeriodeFermee(2025))->toBeNull();
    });

    test('le motif ne tient qu’à l’association courante : l’exercice clôturé d’une autre association ne ferme rien', function () {
        $autre = Association::factory()->create();
        Exercice::withoutGlobalScopes()->create(['association_id' => $autre->id, 'annee' => 2025, 'statut' => StatutExercice::Cloture]);

        expect(app(RattachementExerciceService::class)->motifPeriodeFermee(2025))->toBeNull();
    });

    test('c’est une lecture simple : aucune de ses requêtes ne verrouille', function () {
        Exercice::create(['annee' => 2025, 'statut' => StatutExercice::Cloture]);
        $requetes = [];

        DB::listen(function ($requete) use (&$requetes): void {
            $requetes[] = $requete->sql;
        });

        app(RattachementExerciceService::class)->motifPeriodeFermee(2025);

        // Garde anti-test-vide : le motif a bien lu les exercices.
        expect(array_filter($requetes, fn (string $sql) => str_contains($sql, 'exercices')))->not->toBeEmpty();

        // (Sous SQLite « for update » n'existe pas : le contrôle ne vaut que sous MariaDB.)
        foreach ($requetes as $sql) {
            expect($sql)->not->toContain('for update');
        }
    });

    test('il y a accord avec le service : quand un motif est rendu, rattacher() et retirer() refusent', function (int $anneeCloturee) {
        $ligne = $this->ligneOrigine(7, 175.0);
        $service = app(RattachementExerciceService::class);
        $provision = $service->rattacher($ligne, null, $this->user);
        $autre = $this->ligneOrigine(7, 80.0);

        Exercice::create(['annee' => $anneeCloturee, 'statut' => StatutExercice::Cloture]);

        expect($service->motifPeriodeFermee(2025))->not->toBeNull();
        expect(fn () => $service->rattacher($autre, null, $this->user))->toThrow(ExerciceCloturedException::class);
        expect(fn () => $service->retirer($provision))->toThrow(ExerciceCloturedException::class);
    })->with([
        'N clôturé' => [2025],
        'N+1 clôturé' => [2026],
    ]);
});

// ── La pièce jointe d'un rattachement retiré (D-7) ───────────────────────────

/**
 * Un rattachement de la ligne d'origine, avec une pièce jointe écrite sur le disque comme l'écran
 * l'écrit : `associations/{id}/provisions/{id}/piece-jointe.pdf`.
 *
 * @return array{provision: Provision, chemin: string, dossier: string}
 */
function rattachementAvecPieceJointe(mixed $test, float $montant = 175.0): array
{
    $provision = app(RattachementExerciceService::class)->rattacher($test->ligneOrigine(7, $montant), null, $test->user);

    $dossier = $provision->storagePath('provisions/'.$provision->id);
    $chemin = $dossier.'/piece-jointe.pdf';
    Storage::disk('local')->put($chemin, 'contenu du justificatif');

    $provision->update([
        'piece_jointe_path' => 'piece-jointe.pdf',
        'piece_jointe_nom' => 'justificatif.pdf',
        'piece_jointe_mime' => 'application/pdf',
    ]);

    return ['provision' => $provision, 'chemin' => $chemin, 'dossier' => $dossier];
}

describe('RattachementExerciceService : retirer() et la pièce jointe', function () {
    beforeEach(function () {
        Storage::fake('local');
    });

    test('le retrait supprime le dossier de la pièce jointe', function () {
        ['provision' => $provision, 'chemin' => $chemin, 'dossier' => $dossier] = rattachementAvecPieceJointe($this);
        expect(Storage::disk('local')->exists($chemin))->toBeTrue();
        $spy = Log::spy();

        app(RattachementExerciceService::class)->retirer($provision);

        expect(Storage::disk('local')->exists($chemin))->toBeFalse();
        expect(Storage::disk('local')->exists($dossier))->toBeFalse();
        expect(DB::table('provisions')->where('id', $provision->id)->exists())->toBeFalse();
        // Une suppression réussie ne journalise aucun avertissement.
        $spy->shouldNotHaveReceived('warning');
    });

    test('le retrait ne touche qu’au dossier de SON rattachement', function () {
        ['provision' => $premiere, 'chemin' => $cheminDuPremier] = rattachementAvecPieceJointe($this);
        ['provision' => $seconde, 'chemin' => $cheminDuSecond] = rattachementAvecPieceJointe($this, 90.0);

        app(RattachementExerciceService::class)->retirer($premiere);

        expect(Storage::disk('local')->exists($cheminDuPremier))->toBeFalse();
        expect(Storage::disk('local')->exists($cheminDuSecond))->toBeTrue();
        expect(DB::table('provisions')->where('id', $seconde->id)->exists())->toBeTrue();
    });

    test('un retrait refusé laisse la pièce jointe', function () {
        ['provision' => $provision, 'chemin' => $chemin] = rattachementAvecPieceJointe($this);
        Exercice::create(['annee' => 2026, 'statut' => StatutExercice::Cloture]);

        expect(fn () => app(RattachementExerciceService::class)->retirer($provision))->toThrow(ExerciceCloturedException::class);

        expect(Storage::disk('local')->exists($chemin))->toBeTrue();
        expect(DB::table('provisions')->where('id', $provision->id)->exists())->toBeTrue();
    });

    test('un rattachement sans pièce jointe se retire sans erreur', function () {
        $provision = app(RattachementExerciceService::class)->rattacher($this->ligneOrigine(7, 175.0), null, $this->user);
        $spy = Log::spy();

        app(RattachementExerciceService::class)->retirer($provision);

        expect(DB::table('provisions')->where('id', $provision->id)->exists())->toBeFalse();
        // Un dossier absent n'est pas un échec : la plupart des rattachements n'ont pas de justificatif.
        $spy->shouldNotHaveReceived('warning');
    });

    test('la suppression du dossier a lieu APRÈS le commit', function () {
        ['provision' => $provision] = rattachementAvecPieceJointe($this);
        $niveauALaSuppression = null;

        $disque = Mockery::mock(Storage::disk('local'))->makePartial();
        $disque->shouldReceive('deleteDirectory')->andReturnUsing(function () use (&$niveauALaSuppression): bool {
            $niveauALaSuppression = DB::transactionLevel();

            return true;
        });
        Storage::set('local', $disque);

        $niveauAvant = DB::transactionLevel();
        app(RattachementExerciceService::class)->retirer($provision);

        // Hors de la transaction du service : un fichier supprimé avant un commit qui échouerait
        // laisserait un rattachement toujours en place, sans son justificatif.
        expect($niveauALaSuppression)->toBe($niveauAvant);
    });

    test('un échec du disque n’empêche pas le retrait : il est validé et le problème journalisé', function (string $echec) {
        ['provision' => $provision] = rattachementAvecPieceJointe($this);
        $ecritures = DB::table('transactions')->where('provision_id', $provision->id)->pluck('id')->all();

        $disque = Mockery::mock(Storage::disk('local'))->makePartial();
        $disque->shouldReceive('deleteDirectory')->andReturnUsing(function () use ($echec): bool {
            if ($echec === 'exception') {
                throw new RuntimeException('Disque en lecture seule.');
            }

            return false;
        });
        Storage::set('local', $disque);

        $spy = Log::spy();

        app(RattachementExerciceService::class)->retirer($provision);

        // Le retrait est acquis : rattachement et écritures supprimés.
        expect(DB::table('provisions')->where('id', $provision->id)->exists())->toBeFalse();
        expect(DB::table('transactions')->whereIn('id', $ecritures)->count())->toBe(0);

        // Le dossier orphelin est signalé, avec de quoi le retrouver.
        $spy->shouldHaveReceived('warning')
            ->with(Mockery::on(fn ($message) => str_contains((string) $message, '[Rattachement]')), Mockery::on(
                fn ($contexte) => (int) ($contexte['provision_id'] ?? 0) === (int) $provision->id
                    && str_contains((string) ($contexte['dossier'] ?? ''), 'provisions/'.$provision->id)
            ))
            ->once();
        // Et le retrait est tout de même journalisé comme réussi.
        $spy->shouldHaveReceived('info')->with('[Rattachement] écriture de rattachement retirée', Mockery::any())->once();
    })->with([
        'le disque lève une exception' => ['exception'],
        'le disque répond qu’il n’a pas pu supprimer' => ['faux'],
    ]);
});
