<?php

declare(strict_types=1);

use App\DataTransferObjects\ExtournePayload;
use App\Enums\ModePaiement;
use App\Models\Compte;
use App\Models\Extourne;
use App\Models\Operation;
use App\Models\Provision;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Models\TransactionLigneAffectation;
use App\Services\Compta\GelRattachement;
use App\Services\Compta\RattachementExerciceService;
use App\Services\Compta\ReclassementLigneService;
use App\Services\TransactionExtourneService;
use App\Services\TransactionService;
use App\Tenant\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportTesting\Testable;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Helpers du verrou d'une ligne rattachée (CCA/PCA, spec § 7)
|--------------------------------------------------------------------------
|
| Partagés par les tests des sites du verrou : le reclassement et le retrait de ligne (Task 7),
| l'édition, la suppression et l'annulation (Task 8a), puis l'extourne, la ventilation et la
| synchronisation HelloAsso. Pest charge tests/Helpers pour toute exécution, d'un seul fichier comme
| de la suite complète : déclarés dans un fichier de test, ces helpers manqueraient aux autres.
*/

/** Le message unique du verrou, mot pour mot : ce que l'utilisateur lit. */
const VERROU_MESSAGE = 'Cette pièce porte un rattachement à l’exercice suivant : pour la modifier, retirez d’abord le rattachement '
    .'depuis Exercices > Écritures de rattachement, corrigez, puis repassez-le.';

/**
 * Le message de l'écriture de rattachement (dotation ou extourne générée), mot pour mot : elle ne se
 * modifie, ne se supprime et ne s'annule pas par les écrans ordinaires, elle se retire avec son rattachement.
 */
const VERROU_MESSAGE_ECRITURE = 'Cette écriture a été générée par un rattachement à l’exercice suivant : elle ne se modifie, ne se supprime '
    .'et ne s’annule pas ici. Pour la défaire, retirez le rattachement depuis Exercices > Écritures de rattachement.';

/**
 * Le message du refus d'une annulation par extourne datée au plus tard le dernier jour de N, mot pour mot.
 * Les pièces des tests sont rattachées à l'exercice 2025, qui finit le 31/08/2026 (exercice de septembre à août).
 */
const VERROU_MESSAGE_EXTOURNE_PRECOCE = 'Cette pièce porte un rattachement à l’exercice suivant : l’annuler avec une date au plus tard le 31/08/2026 '
    .'retirerait deux fois le même montant de l’exercice 2025-2026. Datez l’annulation après le 31/08/2026, '
    .'ou retirez d’abord le rattachement depuis Exercices > Écritures de rattachement.';

/**
 * Le même refus quand la date de l'annulation est imposée (l'annulation d'une facture a lieu aujourd'hui) :
 * pas de « Datez l'annulation », sortie que ce parcours n'a pas ; la sortie est d'attendre le premier jour de N+1.
 */
const VERROU_MESSAGE_EXTOURNE_DATE_IMPOSEE = 'Cette pièce porte un rattachement à l’exercice suivant : l’annuler aujourd’hui '
    .'retirerait deux fois le même montant de l’exercice 2025-2026. Retirez d’abord le rattachement depuis '
    .'Exercices > Écritures de rattachement, ou attendez le 01/09/2026 pour l’annuler.';

/** Un compte actif de la même classe que la ligne d'origine, vers lequel la reclasser. */
function verrouAutreCompte(int $classe): Compte
{
    return ($classe === 7 ? Compte::factory()->recette() : Compte::factory()->depense())
        ->create(['association_id' => (int) TenantContext::currentId(), 'actif' => true]);
}

/**
 * Une ligne sœur de $ligne, de la même pièce et de la même classe, sur un autre compte : la
 * sémantique du verrou est par ligne, son gel est celui de la pièce.
 */
function verrouSoeur(int $classe, TransactionLigne $ligne, float $montant = 50.0): TransactionLigne
{
    return TransactionLigne::create([
        'transaction_id' => $ligne->transaction_id,
        'compte_id' => verrouAutreCompte($classe)->id,
        'debit' => $classe === 6 ? $montant : 0,
        'credit' => $classe === 7 ? $montant : 0,
        'montant' => $montant,
        'libelle' => 'Ligne sœur',
    ]);
}

/**
 * Rattache pour de bon (rattacher() : le rattachement et ses deux écritures) la ligne $ligne.
 */
function verrouRattacherAvecEcritures(TestCase $test, TransactionLigne $ligne): Provision
{
    return app(RattachementExerciceService::class)->rattacher($ligne, null, $test->user);
}

/** Le message du refus que lève $action, ou null si elle ne lève pas de RuntimeException. */
function verrouMessageDuRefus(Closure $action): ?string
{
    try {
        $action();
    } catch (RuntimeException $e) {
        return $e->getMessage();
    }

    return null;
}

/**
 * Ce qu'un geste refusé n'a pas le droit de toucher : la pièce de la ligne et TOUTES ses lignes
 * (les gestes des Tasks 8 et 9 touchent la pièce entière), les affectations de ces lignes et de celles des
 * écritures (la ventilation, Task 8c), le rattachement, et les deux écritures du rattachement avec leurs lignes.
 * Lignes complètes, relevées sans aucun filtre : suppressions logiques comprises.
 *
 * @return array{piece: array<string, mixed>, lignes_de_la_piece: list<array<string, mixed>>, affectations: list<array<string, mixed>>, provision: array<string, mixed>, ecritures: list<array<string, mixed>>, lignes: list<array<string, mixed>>}
 */
function verrouEmpreinte(TransactionLigne $ligne, Provision $provision): array
{
    $pieceId = (int) DB::table('transaction_lignes')->where('id', (int) $ligne->id)->value('transaction_id');
    $ecritureIds = DB::table('transactions')->where('provision_id', (int) $provision->id)->orderBy('id')->pluck('id')->all();

    return [
        'piece' => (array) DB::table('transactions')->where('id', $pieceId)->first(),
        'lignes_de_la_piece' => DB::table('transaction_lignes')->where('transaction_id', $pieceId)->orderBy('id')->get()
            ->map(fn (object $l): array => (array) $l)->all(),
        'affectations' => DB::table('transaction_ligne_affectations')
            ->whereIn('transaction_ligne_id', DB::table('transaction_lignes')->whereIn('transaction_id', [$pieceId, ...$ecritureIds])->select('id'))
            ->orderBy('id')->get()->map(fn (object $a): array => (array) $a)->all(),
        'provision' => (array) DB::table('provisions')->where('id', (int) $provision->id)->first(),
        'ecritures' => DB::table('transactions')->whereIn('id', $ecritureIds)->orderBy('id')->get()
            ->map(fn (object $ecriture): array => (array) $ecriture)->all(),
        'lignes' => DB::table('transaction_lignes')->whereIn('transaction_id', $ecritureIds)->orderBy('id')->get()
            ->map(fn (object $l): array => (array) $l)->all(),
    ];
}

/**
 * Le geste d'un site du verrou est REFUSÉ : par un refus métier (une RuntimeException, jamais une
 * erreur SQL de la base : une clé étrangère qui s'en chargerait ne serait pas un refus lisible), au
 * message exact (celui du verrou, sauf $message), et sans que rien n'ait bougé (verrouEmpreinte() à
 * l'identique). $ligne est la ligne rattachée, $provision son rattachement.
 */
function verrouAssertRefuse(Closure $geste, TransactionLigne $ligne, Provision $provision, string $message = VERROU_MESSAGE): void
{
    $avant = verrouEmpreinte($ligne, $provision);

    // Gardes anti-test-vide : le rattachement existe, avec ses deux écritures et leurs quatre lignes.
    expect($avant['provision'])->not->toBeEmpty()
        ->and($avant['ecritures'])->toHaveCount(2)
        ->and($avant['lignes'])->toHaveCount(4);

    // Pas de toThrow(Throwable::class) : Pest ne reconnaît que des classes, et prendrait l'interface
    // pour un fragment de message attendu.
    $refus = null;

    try {
        $geste();
    } catch (Throwable $e) {
        $refus = $e;
    }

    expect($refus)->not->toBeNull()
        ->toBeInstanceOf(RuntimeException::class)
        ->not->toBeInstanceOf(QueryException::class);
    expect($refus->getMessage())->toBe($message);

    expect(GelRattachement::MOTIF_PIECE_GELEE)->toBe(VERROU_MESSAGE)
        ->and(GelRattachement::MOTIF_ECRITURE_DE_RATTACHEMENT)->toBe(VERROU_MESSAGE_ECRITURE);

    expect(verrouEmpreinte($ligne, $provision))->toBe($avant);
}

/**
 * Le geste d'un site du verrou PASSE sur une ligne libre : il ne lève rien. L'effet du geste est à
 * la charge de l'appelant.
 */
function verrouAssertPasse(Closure $geste, TransactionLigne $ligne): void
{
    // Garde anti-test-vide : la ligne est bien libre. Relue avec ses suppressions logiques.
    expect(TransactionLigne::withTrashed()->findOrFail($ligne->id)->estRattachee())->toBeFalse();

    expect(verrouMessageDuRefus($geste))->toBeNull();
}

/**
 * Les requêtes de verrou de la garde ne portent AUCUN scope : ni suppression logique, ni tenant
 * (association_id si le contexte est booté, « 1 = 0 » s'il ne l'est pas). C'est la même forme de
 * lecture qui, sans cela, rendrait la garde passante sur une ligne supprimée ou hors contexte.
 */
function verrouAssertSansScope(object $requete): void
{
    expect($requete->sql)->not->toContain('deleted_at')
        ->and($requete->sql)->not->toContain('association_id')
        ->and($requete->sql)->not->toContain('1 = 0');
}

/**
 * Une pièce équilibrée dont la ligne SŒUR (la dernière, après la contrepartie) est rattachée, et sa
 * ligne d'origine, libre.
 *
 * @return array{libre: TransactionLigne, soeur: TransactionLigne, provision: Provision, piece: Transaction} la ligne
 *                                                                                                           libre, la sœur rattachée, son
 *                                                                                                           rattachement, la pièce
 */
function verrouPieceDontLaSoeurEstRattachee(TestCase $test, int $classe): array
{
    $libre = $test->ligneOrigineEquilibree($classe);
    $soeur = verrouSoeur($classe, $libre);

    return [
        'libre' => $libre,
        'soeur' => $soeur,
        'provision' => verrouRattacherAvecEcritures($test, $soeur),
        'piece' => Transaction::findOrFail($libre->transaction_id),
    ];
}

/**
 * Rattache une ligne d'origine de la classe $classe, et rend, avec elle et son rattachement, la ligne de
 * RÉSULTAT (classe 6 ou 7) de l'écriture de rattachement $ecriture : « normale » (la dotation) ou
 * « extourne » (le miroir). Cette ligne ne porte aucun rattachement : c'est sa pièce qui en est une.
 *
 * @return array{ligne: TransactionLigne, provision: Provision, piece: Transaction, ecriture: TransactionLigne}
 *                                                                                                              la ligne d'origine,
 *                                                                                                              son rattachement, la pièce de
 *                                                                                                              l'écriture, sa ligne de résultat
 */
function verrouEcritureDeRattachement(TestCase $test, int $classe, string $ecriture): array
{
    $ligne = $test->ligneOrigineEquilibree($classe);
    $provision = verrouRattacherAvecEcritures($test, $ligne);
    $piece = Transaction::where('provision_id', (int) $provision->id)->where('type_ecriture', $ecriture)->sole();

    return [
        'ligne' => $ligne,
        'provision' => $provision,
        'piece' => $piece,
        'ecriture' => $piece->lignes()->whereHas('compte', fn ($q) => $q->whereIn('classe', [6, 7]))->sole(),
    ];
}

/** Le geste du site de ReclassementLigneService : reclasser $ligne vers $compte, avec un motif suffisant. */
function verrouReclasser(TestCase $test, TransactionLigne $ligne, Compte $compte): TransactionLigne
{
    return app(ReclassementLigneService::class)->reclasser(
        $ligne, (int) $compte->id, null, null, 'Motif de reclassement suffisant', $test->user,
    );
}

/** @return list<int> les identifiants des lignes que le formulaire de transaction affiche */
function verrouIdsAffiches(Testable $formulaire): array
{
    return collect($formulaire->get('lignes'))->pluck('id')->map(fn ($id): int => (int) $id)->all();
}

/**
 * Les données d'édition d'une pièce telle qu'elle est en base, pour TransactionService::update() : son
 * libellé change, tout le reste est repris. Les lignes sont celles de produit ou de charge (classes 6 et 7) :
 * le formulaire ne porte pas les contreparties 411/401, update() les régénère.
 *
 * @return array{0: array<string, mixed>, 1: list<array<string, mixed>>} les données de la pièce, ses lignes
 */
function verrouDonneesDEdition(Transaction $piece, string $libelle = 'Pièce corrigée'): array
{
    $lignes = TransactionLigne::where('transaction_id', (int) $piece->id)
        ->with('compte')
        ->orderBy('id')
        ->get()
        ->filter(fn (TransactionLigne $ligne): bool => in_array((int) $ligne->compte->classe, [6, 7], true))
        ->values();

    $donnees = [
        'type' => $piece->type->value,
        'date' => $piece->date->toDateString(),
        'libelle' => $libelle,
        'montant_total' => number_format((float) $lignes->sum('montant'), 2, '.', ''),
        'mode_paiement' => null,
        'tiers_id' => null,
        'compte_id' => null,
    ];

    return [$donnees, $lignes->map(fn (TransactionLigne $ligne): array => [
        'id' => (int) $ligne->id,
        'compte_id' => (int) $ligne->compte_id,
        'montant' => (float) $ligne->montant,
        'operation_id' => $ligne->operation_id,
        'seance' => $ligne->seance,
        'notes' => null,
    ])->all()];
}

/**
 * Une affectation déjà posée sur $ligne (le montant entier, sur une opération créée pour l'occasion) : ce que
 * « Supprimer la ventilation » doit défaire, et ce que « Ventiler » remplace. Une ligne rattachée n'a jamais
 * d'affectation (elle serait refusée à la détection) : sur elle, c'est un état forgé après coup, seule façon de
 * prouver qu'un geste refusé n'a rien effacé.
 */
function verrouSemerAffectation(TransactionLigne $ligne): TransactionLigneAffectation
{
    return TransactionLigneAffectation::create([
        'transaction_ligne_id' => (int) $ligne->id,
        'operation_id' => Operation::factory()->create()->id,
        'seance' => null,
        'montant' => (string) $ligne->montant,
        'notes' => 'Affectation d’avant',
    ]);
}

/**
 * Le geste des sites de la ventilation (Task 8c) sur $ligne : « affecter » (affecterLigne(), le montant entier
 * sur une NOUVELLE opération, créée ici, avant le geste) ou « supprimer » (supprimerAffectations()).
 */
function verrouVentiler(string $nom, TransactionLigne $ligne): Closure
{
    $service = app(TransactionService::class);
    $operation = Operation::factory()->create();

    return match ($nom) {
        'affecter' => fn () => $service->affecterLigne($ligne, [[
            'operation_id' => (int) $operation->id,
            'seance' => null,
            'montant' => (string) $ligne->montant,
            'notes' => null,
        ]]),
        'supprimer' => fn () => $service->supprimerAffectations($ligne),
    };
}

/**
 * L'effet d'un des gestes de verrouVentiler() qui a PASSÉ sur $ligne : « affecter » laisse une seule affectation,
 * celle du geste (l'ancienne, semée, est remplacée) ; « supprimer » n'en laisse aucune. Relu sans aucun filtre.
 */
function verrouAssertEffetVentilation(string $nom, TransactionLigne $ligne, ?int $ancienneId): void
{
    $affectations = DB::table('transaction_ligne_affectations')->where('transaction_ligne_id', (int) $ligne->id)->get();

    if ($nom === 'supprimer') {
        expect($affectations)->toBeEmpty();

        return;
    }

    expect($affectations)->toHaveCount(1)
        ->and((int) $affectations->first()->id)->not->toBe($ancienneId);
}

/**
 * Le geste de TransactionService qui réécrit la pièce : « update » (son libellé change), « delete » ou
 * « annuler ». Les trois sites de la Task 8a ; l'extourne (8b) a ses propres tests.
 */
function verrouGeste(string $nom, Transaction $piece): Closure
{
    $service = app(TransactionService::class);

    return match ($nom) {
        'update' => fn (): Transaction => $service->update($piece, ...verrouDonneesDEdition($piece)),
        'delete' => fn () => $service->delete($piece),
        'annuler' => fn () => $service->annuler($piece, 'Motif d’annulation'),
    };
}

/**
 * L'effet d'un des gestes de verrouGeste() qui a PASSÉ sur la pièce $pieceId : le libellé corrigé,
 * ou la pièce et toutes ses lignes supprimées logiquement. Relu sans aucun filtre.
 */
function verrouAssertEffet(string $nom, int $pieceId): void
{
    $piece = (array) DB::table('transactions')->where('id', $pieceId)->first();
    $lignes = DB::table('transaction_lignes')->where('transaction_id', $pieceId)->get();

    expect($piece)->not->toBeEmpty();

    if ($nom === 'update') {
        expect($piece['libelle'])->toBe('Pièce corrigée')
            ->and($piece['deleted_at'])->toBeNull();

        return;
    }

    expect($piece['deleted_at'])->not->toBeNull()
        ->and($lignes)->not->toBeEmpty()
        ->and($lignes->whereNull('deleted_at'))->toBeEmpty();

    if ($nom === 'annuler') {
        expect($piece['motif_suppression'])->toBe('Motif d’annulation');
    }
}

/**
 * L'état d'une pièce et de toutes ses lignes en base, sans aucun filtre (suppressions logiques comprises) :
 * pour les gestes refusés sur une pièce qui n'a pas de rattachement à relever.
 *
 * @return array{piece: array<string, mixed>, lignes: list<array<string, mixed>>}
 */
function verrouEmpreinteDeLaPiece(int $pieceId): array
{
    return [
        'piece' => (array) DB::table('transactions')->where('id', $pieceId)->first(),
        'lignes' => DB::table('transaction_lignes')->where('transaction_id', $pieceId)->orderBy('id')->get()
            ->map(fn (object $l): array => (array) $l)->all(),
    ];
}

/**
 * Classe les requêtes émises par la garde de la pièce (verrouillerPieceNonGelee()) : la liste des lots 401/411,
 * leur verrou, le verrou de la pièce, celui de ses lignes, la lecture verrouillante des rattachements. Chacune
 * doit se trouver EXACTEMENT UNE fois : une lecture en double (la pièce verrouillée deux fois) ou manquante
 * fait échouer, avec l'inventaire des requêtes. Rend nom => indice, dans l'ordre où elles ont été émises.
 *
 * @param  list<object>  $requetes
 * @return array<string, int>
 */
function verrouIndicesDesVerrous(array $requetes): array
{
    $motifs = [
        'liste des lots' => '/^select\s+[`"]id[`"],\s*[`"]poste_tiers_parent_id[`"]\s+from\s+[`"]transaction_lignes[`"]/i',
        'lots' => '/from\s+[`"]transaction_lignes[`"]\s+where\s*\(\s*[`"]transaction_lignes[`"]\.[`"]id[`"]\s*=\s*\?\s+or\s+[`"]poste_tiers_parent_id[`"]\s*=\s*\?/i',
        'pièce' => '/from\s+[`"]transactions[`"]\s+where\s+[`"]transactions[`"]\.[`"]id[`"]\s*=\s*\?\s+limit\s+1/i',
        'lignes' => '/^select\s+[`"]id[`"]\s+from\s+[`"]transaction_lignes[`"]\s+where\s+[`"]transaction_id[`"]\s*=\s*\?\s+order\s+by/i',
        'rattachements' => '/from\s+[`"]provisions[`"]\s+where\s+[`"]transaction_ligne_id[`"]\s+in\s*\(/i',
    ];

    $inventaire = implode("\n", array_map(fn (object $r, int $i): string => "#{$i} {$r->sql}", $requetes, array_keys($requetes)));
    $indices = [];

    foreach ($motifs as $nom => $motif) {
        $trouvees = array_keys(array_filter($requetes, fn (object $r): bool => preg_match($motif, $r->sql) === 1));

        expect($trouvees)->toHaveCount(1, "« {$nom} » doit être lu une fois et une seule. Requêtes :\n{$inventaire}");

        $indices[$nom] = $trouvees[0];
    }

    asort($indices);

    return $indices;
}

/**
 * Une pièce prête à être annulée par extourne (équilibrée, avec un mode de paiement), dont la ligne de
 * résultat est rattachée à l'exercice 2025 (la pièce est datée du 08/04/2026, N finit le 31/08/2026).
 *
 * @return array{ligne: TransactionLigne, provision: Provision, piece: Transaction}
 */
function verrouPieceRattacheeAExtourner(TestCase $test, int $classe): array
{
    $ligne = $test->ligneOrigineEquilibree($classe, $classe === 7 ? 175.0 : 90.0, ModePaiement::Virement);

    return [
        'ligne' => $ligne,
        'provision' => verrouRattacherAvecEcritures($test, $ligne),
        'piece' => Transaction::findOrFail($ligne->transaction_id),
    ];
}

/**
 * Le geste du site de TransactionExtourneService : annuler $piece par extourne, la pièce miroir datée de
 * $date (Y-m-d). Le mode de paiement est celui du remboursement : il est donné, une écriture de
 * rattachement n'en porte pas.
 */
function verrouExtourner(Transaction $piece, string $date): Closure
{
    return fn (): Extourne => app(TransactionExtourneService::class)->extourner(
        $piece,
        ExtournePayload::fromOrigine($piece, ['date' => $date, 'mode_paiement' => ModePaiement::Virement]),
    );
}

/**
 * Comme verrouAssertRefuse() pour l'extourne, qui crée une pièce miroir et consomme un numéro : rien de tout
 * cela n'a eu lieu. Les compteurs (pièces, lignes, annulations) sont ceux de la base entière ; et aucune
 * requête de la transaction n'a touché à la numérotation, qui n'est prise qu'une fois la garde passée.
 * Un refus dont la numérotation aurait précédé la garde serait annulé par la transaction, et ne se verrait
 * plus dans les compteurs : c'est la requête qui le trahit.
 */
function verrouAssertExtourneRefusee(Closure $geste, TransactionLigne $ligne, Provision $provision, string $message = VERROU_MESSAGE_EXTOURNE_PRECOCE): void
{
    $compteurs = fn (): array => [
        'pièces' => DB::table('transactions')->count(),
        'lignes' => DB::table('transaction_lignes')->count(),
        'annulations' => DB::table('extournes')->count(),
    ];
    $avant = $compteurs();

    $niveauAvant = DB::transactionLevel();
    $requetes = [];
    DB::listen(function ($requete) use (&$requetes, $niveauAvant): void {
        if (DB::transactionLevel() > $niveauAvant) {
            $requetes[] = $requete->sql;
        }
    });

    verrouAssertRefuse($geste, $ligne, $provision, $message);

    expect($compteurs())->toBe($avant);
    expect(array_values(array_filter($requetes, fn (string $sql): bool => str_contains($sql, 'sequences'))))->toBe([]);
}

/**
 * Les classes de l'application que le constructeur de $classe demande, de proche en proche. Sert à prouver
 * l'absence de cycle d'injection : GelRattachement n'atteint ni ProvisionPDService, ni EcritureGenerator, ni aucun
 * des gestes qu'il protège ; RattachementExerciceService n'atteint jamais TransactionExtourneService.
 *
 * @return list<class-string>
 */
function verrouDependancesDuConstructeur(string $classe, array &$vues = []): array
{
    $constructeur = (new ReflectionClass($classe))->getConstructor();

    foreach ($constructeur?->getParameters() ?? [] as $parametre) {
        $type = $parametre->getType();

        if (! $type instanceof ReflectionNamedType || $type->isBuiltin() || ! str_starts_with($type->getName(), 'App\\')) {
            continue;
        }

        if (! in_array($type->getName(), $vues, true)) {
            $vues[] = $type->getName();
            verrouDependancesDuConstructeur($type->getName(), $vues);
        }
    }

    return $vues;
}

/**
 * Combien de fois le formulaire affiche $motif, tel que le rendu l'écrit (échappé). Une pièce gelée l'affiche
 * déjà en tête, à l'ouverture : un refus qui s'y ajoute le fait paraître une seconde fois, et ne reste pas
 * affiché après le geste suivant.
 */
function verrouOccurrencesDuMotif(Testable $formulaire, string $motif): int
{
    return substr_count($formulaire->html(), e($motif));
}

/**
 * Le bouton d'enregistrement du formulaire de transaction, tel qu'il est rendu : son HTML d'ouverture, avec ses
 * attributs, ou null s'il n'est pas rendu (formulaire en lecture seule). C'est le seul bouton de type « submit ».
 */
function verrouBoutonEnregistrer(Testable $formulaire): ?string
{
    $nombre = preg_match_all('/<button\b[^>]*type="submit"[^>]*>/', $formulaire->html(), $trouves);

    expect($nombre)->toBeLessThanOrEqual(1);

    return $trouves[0][0] ?? null;
}

/**
 * Le <form> porte-t-il le repère de lecture seule (attribut data-lecture-seule, posé par la vue) ? Un repère stable,
 * qui ne dépend ni d'un libellé ni d'une classe de bouton.
 */
function verrouFormulaireEnLectureSeule(Testable $formulaire): bool
{
    return preg_match('/<form\b[^>]*\bdata-lecture-seule\b/', $formulaire->html()) === 1;
}

/**
 * Le formulaire est en LECTURE SEULE, comme pour un exercice clôturé : le repère de la vue, aucun bouton
 * d'enregistrement, et, pour une pièce gelée, le motif du gel dans l'état du composant.
 */
function verrouAssertLectureSeule(Testable $formulaire): void
{
    expect(verrouFormulaireEnLectureSeule($formulaire))->toBeTrue()
        ->and(verrouBoutonEnregistrer($formulaire))->toBeNull()
        ->and($formulaire->get('motifGel'))->not->toBeNull();
}

/** Le formulaire est ÉDITABLE : pas de repère de lecture seule, un bouton d'enregistrement actif, aucun motif de gel. */
function verrouAssertEditable(Testable $formulaire): void
{
    expect(verrouFormulaireEnLectureSeule($formulaire))->toBeFalse()
        ->and(verrouBoutonEnregistrer($formulaire))->not->toBeNull()->not->toContain('disabled')
        ->and($formulaire->get('motifGel'))->toBeNull();
}

/**
 * Tous les champs du formulaire liés à $propriete (wire:model, avec ou sans .live) sont-ils désactivés ? Un champ
 * absent du rendu fait échouer : « désactivé » ne veut pas dire « pas là ».
 */
function verrouChampDesactive(Testable $formulaire, string $propriete): bool
{
    $motif = '/<(?:input|select|textarea)\b[^>]*\bwire:model(?:\.live)?="'.preg_quote($propriete, '/').'"[^>]*>/';

    expect(preg_match_all($motif, $formulaire->html(), $trouves))->toBeGreaterThan(0, "Le champ « {$propriete} » n'est pas rendu.");

    foreach ($trouves[0] as $balise) {
        if (preg_match('/\sdisabled\b/', $balise) !== 1) {
            return false;
        }
    }

    return true;
}

/** @return list<int> les lignes pour lesquelles le formulaire offre « Ventiler » / « Modifier la ventilation » */
function verrouLignesVentilables(Testable $formulaire): array
{
    preg_match_all('/wire:click="ouvrirVentilation\((\d+)\)"/', $formulaire->html(), $trouves);

    return array_map('intval', $trouves[1]);
}
