<?php

declare(strict_types=1);

use App\Models\Provision;
use App\Models\TransactionLigne;
use App\Models\User;
use App\Services\Compta\Migrations\SystemeSeeder;
use App\Tenant\TenantContext;
use Illuminate\Database\QueryException;

/*
|--------------------------------------------------------------------------
| Verrou d'une ligne rattachée à l'exercice (CCA/PCA)
|--------------------------------------------------------------------------
|
| Une ligne portée par un rattachement est verrouillée. TransactionLigne::estRattachee()
| est la seule source de vérité de ce verrou : toutes les gardes d'écriture (mise à
| jour, suppression, annulation, ventilation, extourne, reclassement, synchronisation
| HelloAsso) l'appellent. Ce fichier accueille aussi les tests de ces gardes.
|
| Spec : docs/specs/2026-09-30-provisions-rattachement-exercice-design.md (§ 6.4, § 7).
*/

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->user->associations()->attach(TenantContext::currentId(), ['role' => 'admin', 'joined_at' => now()]);
    $this->actingAs($this->user);

    // 486 et 487 : les comptes de régularisation, posés par le seeder système.
    SystemeSeeder::seed();
});

/**
 * Le rattachement de l'exercice 2025 qui porte la ligne : tout ou rien, le montant
 * est celui de la ligne d'origine. Aucune écriture comptable n'est générée ici.
 */
function verrouRattacher(TransactionLigne $ligne): Provision
{
    return Provision::create([
        'association_id' => TenantContext::currentId(),
        'exercice' => 2025,
        'transaction_ligne_id' => $ligne->id,
        'montant' => max((float) $ligne->debit, (float) $ligne->credit),
        'saisi_par' => auth()->id(),
    ]);
}

describe('TransactionLigne::estRattachee()', function () {
    it('est fausse sans rattachement, vraie dès qu\'il existe, fausse après son retrait physique', function (int $classe) {
        $ligne = $this->ligneOrigine($classe);

        expect($ligne->estRattachee())->toBeFalse();

        $provision = verrouRattacher($ligne);

        expect(Provision::count())->toBe(1)
            ->and($ligne->fresh()->estRattachee())->toBeTrue();

        // Le retrait du § 6.4 supprime la ligne de provisions : la ligne d'origine est libre.
        $provision->forceDelete();

        expect(Provision::withTrashed()->count())->toBe(0)
            ->and($ligne->fresh()->estRattachee())->toBeFalse();
    })->with([
        'charge (classe 6, CCA)' => [6],
        'produit (classe 7, PCA)' => [7],
    ]);

    it('ne verrouille que la ligne rattachée, pas les autres lignes', function () {
        $rattachee = $this->ligneOrigine(7);
        $libre = $this->ligneOrigine(7);

        verrouRattacher($rattachee);

        expect(Provision::count())->toBe(1)
            ->and($rattachee->fresh()->estRattachee())->toBeTrue()
            ->and($libre->fresh()->estRattachee())->toBeFalse();
    });

    it('reste vraie pour un rattachement supprimé logiquement : la clé étrangère le compte encore', function () {
        $ligne = $this->ligneOrigine(7);
        $provision = verrouRattacher($ligne);

        // Garde anti-test-vide : le rattachement verrouille avant d'être supprimé logiquement.
        expect($ligne->fresh()->estRattachee())->toBeTrue();

        $provision->delete();

        // Le rattachement est caché au scope par défaut, mais bien présent en base.
        expect(Provision::count())->toBe(0)
            ->and(Provision::withTrashed()->count())->toBe(1)
            ->and($ligne->fresh()->estRattachee())->toBeTrue();
    });

    it('reste vraie hors contexte d\'association : le verrou ne s\'ouvre pas faute de tenant', function () {
        $ligne = $this->ligneOrigine(7);
        verrouRattacher($ligne);
        $ligne = $ligne->fresh();

        // Garde anti-test-vide : dans le tenant, le rattachement est visible et verrouille.
        expect(Provision::count())->toBe(1)
            ->and($ligne->estRattachee())->toBeTrue();

        TenantContext::clear();

        // La lecture est fail-closed hors tenant (c'est la sécurité), preuve que le
        // rattachement existe toujours mais que le scope le masquerait.
        expect(Provision::count())->toBe(0)
            ->and($ligne->estRattachee())->toBeTrue();
    });

    it('reste vraie pour un rattachement supprimé logiquement ET hors contexte d\'association', function () {
        $ligne = $this->ligneOrigine(6);
        verrouRattacher($ligne)->delete();
        $ligne = $ligne->fresh();

        expect(Provision::withTrashed()->count())->toBe(1);

        TenantContext::clear();

        expect($ligne->estRattachee())->toBeTrue();
    });

    it('s\'aligne sur la clé étrangère : la base refuse de supprimer physiquement une ligne dont le rattachement est supprimé logiquement', function () {
        $ligne = $this->ligneOrigine(7);
        $provision = verrouRattacher($ligne);
        $provision->delete();

        // Garde anti-test-vide : le rattachement soft-deleté existe, et l'application
        // dit déjà la ligne verrouillée, comme la base va le faire.
        expect(Provision::withTrashed()->count())->toBe(1)
            ->and($ligne->fresh()->estRattachee())->toBeTrue();

        // C'est ce que fait TransactionService::update() : un forceDelete des lignes.
        // Si estRattachee() ignorait ce rattachement, la garde laisserait passer et
        // la base lèverait cette erreur SQL brute à la place d'un refus lisible.
        expect(fn () => TransactionLigne::whereKey($ligne->id)->forceDelete())
            ->toThrow(QueryException::class);

        expect(TransactionLigne::whereKey($ligne->id)->exists())->toBeTrue();
    });

    it('permet la suppression physique de la ligne une fois le rattachement retiré physiquement', function () {
        $ligne = $this->ligneOrigine(7);
        verrouRattacher($ligne)->forceDelete();

        expect($ligne->fresh()->estRattachee())->toBeFalse();

        TransactionLigne::whereKey($ligne->id)->forceDelete();

        expect(TransactionLigne::withTrashed()->whereKey($ligne->id)->exists())->toBeFalse();
    });
});
