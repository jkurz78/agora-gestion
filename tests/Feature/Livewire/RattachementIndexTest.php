<?php

declare(strict_types=1);

use App\DataTransferObjects\ExtournePayload;
use App\Enums\CasProvision;
use App\Enums\JournalComptable;
use App\Enums\ModePaiement;
use App\Enums\StatutExercice;
use App\Enums\TypeTransaction;
use App\Livewire\Provisions\ProvisionIndex;
use App\Models\Association;
use App\Models\Compte;
use App\Models\Exercice;
use App\Models\Operation;
use App\Models\Provision;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Models\TransactionLigneAffectation;
use App\Models\User;
use App\Services\Compta\Migrations\SystemeSeeder;
use App\Services\Compta\RattachementExerciceService;
use App\Services\TransactionExtourneService;
use App\Tenant\TenantContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Exceptions\PublicPropertyNotFoundException;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| L'écran « Exercices > Écritures de rattachement » (spec § 6)
|--------------------------------------------------------------------------
|
| Ce que l'écran montre et ce qu'il déclenche : la zone « À rattacher » (la détection), la recherche libre,
| les lignes écartées, la modale « Constater d'avance » (le cas en clair, ce qui est hérité, l'aperçu des deux
| écritures, les notes et le justificatif), les « Rattachements de l'exercice » et leur retrait. Les règles
| elles-mêmes — ce qui est rattachable, le sens des écritures, la période fermée — sont éprouvées là où elles
| vivent (RattachementExerciceServiceTest, CandidatsRattachementQueryTest, SchemaRattachementTest) : ici, on
| prouve que l'écran les LIT au lieu d'en refaire une copie, et que ce qu'il affiche est ce qui s'écrit.
|
| Le HTML se lit par des repères stables (`data-zone`, `data-ligne-id`, `data-col`, `data-role`), jamais par
| une classe CSS : la mise en page est faite pour changer, ces repères non.
|
| Exercice 2025 = 01/09/2025 → 31/08/2026 ; horloge figée au 15/01/2026 ; la pièce d'origine est du 08/04/2026.
*/

beforeEach(function () {
    $this->association = TenantContext::current();
    $this->user = User::factory()->create(['nom' => 'Mireille Trésorière']);
    $this->user->associations()->attach($this->association->id, ['role' => 'admin', 'joined_at' => now()]);
    $this->actingAs($this->user);
    session(['current_association_id' => $this->association->id, 'exercice_actif' => 2025]);

    // 486 et 487 : les comptes de régularisation, posés par le seeder système.
    SystemeSeeder::seed();
    Storage::fake('local');
});

// ── Helpers ──────────────────────────────────────────────────────────────────

/** Un compte par son numéro, dans l'association courante. */
function ecranCompte(string $numero, string $intitule): Compte
{
    return Compte::where('numero_pcg', $numero)->first()
        ?? Compte::factory()->numero($numero)->create([
            'association_id' => (int) TenantContext::currentId(),
            'intitule' => $intitule,
            'actif' => true,
        ]);
}

/** Une opération de l'association courante. Par défaut celle du cas réel : du 18/09/2026 au 18/06/2027. */
function ecranOperation(string $debut = '2026-09-18', ?string $fin = '2027-06-18', string $nom = 'EquiThe FE sept 2026'): Operation
{
    return Operation::factory()->create([
        'association_id' => (int) TenantContext::currentId(),
        'type_operation_id' => null,
        'nom' => $nom,
        'date_debut' => $debut,
        'date_fin' => $fin,
    ]);
}

/**
 * Une ligne de l'association courante, pièce comprise, par défaut un produit de 175 € au crédit sur une pièce
 * de vente du 08/04/2026. Les clés : classe, montant, date, numero_piece, libelle (de la ligne), piece_libelle,
 * journal, operation, compte, debit, credit, tiers, seance, type_ecriture.
 *
 * @param  array<string, mixed>  $o
 */
function ecranLigne(array $o = []): TransactionLigne
{
    $o += [
        'classe' => 7, 'montant' => 175.0, 'date' => '2026-04-08', 'numero_piece' => null,
        'libelle' => 'Ligne d’origine', 'piece_libelle' => 'Pièce d’origine', 'journal' => null,
        'operation' => null, 'compte' => null, 'debit' => null, 'credit' => null, 'tiers' => null,
        'seance' => null, 'type_ecriture' => 'normale',
    ];

    $compte = $o['compte'] ?? ($o['classe'] === 7 ? Compte::factory()->recette() : Compte::factory()->depense())
        ->create(['association_id' => (int) TenantContext::currentId(), 'actif' => true]);

    $piece = Transaction::create([
        'association_id' => (int) TenantContext::currentId(),
        'type' => $o['classe'] === 7 ? TypeTransaction::Recette : TypeTransaction::Depense,
        'date' => $o['date'],
        'libelle' => $o['piece_libelle'],
        'numero_piece' => $o['numero_piece'],
        'montant_total' => $o['montant'],
        'tiers_id' => $o['tiers']?->id,
        'saisi_par' => auth()->id(),
        'equilibree' => true,
        'type_ecriture' => $o['type_ecriture'],
        'journal' => $o['journal'] ?? ($o['classe'] === 7 ? JournalComptable::Vente : JournalComptable::Achat),
    ]);

    return TransactionLigne::create([
        'transaction_id' => $piece->id,
        'compte_id' => $compte->id,
        'debit' => $o['debit'] ?? ($o['classe'] === 6 ? $o['montant'] : 0),
        'credit' => $o['credit'] ?? ($o['classe'] === 7 ? $o['montant'] : 0),
        'montant' => $o['montant'],
        'operation_id' => $o['operation']?->id,
        'seance' => $o['seance'],
        'libelle' => $o['libelle'],
    ]);
}

/**
 * Le cas réel du 29/09 : transaction_lignes#145, 706B Parcours thérapeutiques, 175 € au crédit, pièce de vente
 * du 08/04/2026, ventilée sur l'opération « EquiThe FE sept 2026 » (18/09/2026 → 18/06/2027), tiers Claire Durand.
 *
 * @return array{ligne: TransactionLigne, operation: Operation, tiers: Tiers, compte: Compte}
 */
function ecranCasReel(?int $seance = null): array
{
    $compte = ecranCompte('706B', 'Parcours thérapeutiques');
    $operation = ecranOperation();
    $tiers = Tiers::factory()->create([
        'association_id' => (int) TenantContext::currentId(),
        'type' => 'particulier',
        'nom' => 'Durand',
        'prenom' => 'Claire',
    ]);

    $ligne = ecranLigne([
        'compte' => $compte, 'operation' => $operation, 'tiers' => $tiers, 'numero_piece' => 'R-2026-0122',
        'libelle' => 'Parcours thérapeutiques — Claire Durand', 'piece_libelle' => 'HelloAsso — parcours', 'seance' => $seance,
    ]);

    return compact('ligne', 'operation', 'tiers', 'compte');
}

/** Une ligne ventilée par affectations sur deux opérations qui démarrent après l'exercice. */
function ecranLigneVentilee(): TransactionLigne
{
    $ligne = ecranLigne(['montant' => 200.0, 'libelle' => 'Subvention affectée', 'numero_piece' => 'S-2026-0007']);
    TransactionLigneAffectation::create(['transaction_ligne_id' => $ligne->id, 'operation_id' => ecranOperation('2026-10-01', '2027-03-01', 'Stage de Toussaint')->id, 'montant' => 100]);
    TransactionLigneAffectation::create(['transaction_ligne_id' => $ligne->id, 'operation_id' => ecranOperation('2026-09-18', '2027-06-18', 'EquiThe FE sept 2026')->id, 'montant' => 100]);

    return $ligne;
}

function ecranDom(string $html): DOMXPath
{
    $dom = new DOMDocument;
    libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="UTF-8">'.$html);
    libxml_clear_errors();

    return new DOMXPath($dom);
}

/** Le texte d'un nœud, espaces (insécables compris) ramenés à un seul. */
function ecranTexte(?DOMNode $noeud): ?string
{
    return $noeud === null ? null : trim(preg_replace('/\s+/u', ' ', str_replace(["\u{00A0}", "\u{202F}"], ' ', $noeud->textContent)));
}

/** Le premier nœud d'une requête XPath, ou null. */
function ecranNoeud(mixed $composant, string $xpath): ?DOMNode
{
    return ecranDom($composant->html())->query($xpath)->item(0);
}

/** La cellule `$col` de la ligne `$id` (data-ligne-id, ou data-provision-id) de la zone `$zone`. */
function ecranCellule(mixed $composant, string $zone, int $id, string $col, string $cle = 'data-ligne-id'): ?DOMNode
{
    return ecranNoeud($composant, sprintf('//*[@data-zone="%s"]//tr[@%s="%d"]/td[@data-col="%s"]', $zone, $cle, $id, $col));
}

function ecranNombreDeNoeuds(mixed $composant, string $xpath): int
{
    return ecranDom($composant->html())->query($xpath)->length;
}

/** Le texte d'un élément repéré par `data-role`. */
function ecranRole(mixed $composant, string $role): ?string
{
    return ecranTexte(ecranNoeud($composant, sprintf('//*[@data-role="%s"]', $role)));
}

/** Rattache la ligne par le vrai service, au nom de l'utilisateur connecté. */
function ecranRattacher(TransactionLigne $ligne, ?string $notes = null): Provision
{
    return app(RattachementExerciceService::class)->rattacher($ligne->fresh(), $notes, auth()->user());
}

/**
 * Les requêtes émises pendant $action, dont la forme correspond à $motif.
 *
 * @return list<string>
 */
function ecranRequetes(Closure $action, string $motif): array
{
    $requetes = [];

    DB::listen(function ($requete) use (&$requetes, $motif): void {
        if (preg_match($motif, $requete->sql) === 1) {
            $requetes[] = $requete->sql;
        }
    });

    $action();

    return $requetes;
}

/**
 * Ce qu'un rattachement a écrit, ligne par ligne, pour la comparaison avec l'aperçu : la date, le compte, le
 * débit, le crédit et le libellé de chacune des quatre lignes, triées par date puis par compte.
 *
 * @return list<array{date: string, compte: string, debit: float, credit: float, libelle: string}>
 */
function ecranEcrituresEcrites(Provision $provision): array
{
    return Transaction::where('provision_id', $provision->id)->with('lignes.compte')->get()
        ->flatMap(fn (Transaction $ecriture) => $ecriture->lignes->map(fn (TransactionLigne $l): array => [
            'date' => $ecriture->date->toDateString(),
            'compte' => (string) $l->compte->numero_pcg,
            'debit' => round((float) $l->debit, 2),
            'credit' => round((float) $l->credit, 2),
            'libelle' => (string) $l->libelle,
        ]))
        ->sortBy(fn (array $l): string => $l['date'].'|'.$l['compte'])
        ->values()
        ->all();
}

/**
 * Ce que l'aperçu de la modale montre, sous la même forme.
 *
 * @param  array<string, mixed>  $modale
 * @return list<array{date: string, compte: string, debit: float, credit: float, libelle: string}>
 */
function ecranEcrituresApercues(array $modale): array
{
    return collect($modale['apercu'])
        ->flatMap(fn (array $ecriture) => collect($ecriture['lignes'])->map(fn (array $l): array => [
            'date' => $ecriture['date']->toDateString(),
            'compte' => $l['numero_pcg'],
            'debit' => round($l['debit'], 2),
            'credit' => round($l['credit'], 2),
            'libelle' => $ecriture['libelle'],
        ]))
        ->sortBy(fn (array $l): string => $l['date'].'|'.$l['compte'])
        ->values()
        ->all();
}

// ── D-9 · D-1 : le menu, la page, l'exercice ─────────────────────────────────

describe('Libellés, exercice', function () {
    test('le menu, le titre de la page et l’écran disent « Écritures de rattachement », plus « provisions »', function () {
        $reponse = $this->get(route('exercices.provisions'));

        $reponse->assertOk();
        $reponse->assertSee('<title>Écritures de rattachement</title>', false);
        // Le menu : le lien de la page, et lui seul, change de nom.
        $reponse->assertSee('Écritures de rattachement', false);
        $reponse->assertDontSee('Écritures de provisions', false);
        $reponse->assertDontSee('Écritures de provisions');
    });

    test('une phrase sous le titre dit ce que fait l’écran, sans jargon', function () {
        $phrase = ecranRole(Livewire::test(ProvisionIndex::class), 'introduction');

        expect($phrase)->toContain('concerner le suivant');
        expect($phrase)->toContain('2025-2026');
        expect($phrase)->toContain('deux écritures');

        foreach (['CCA', 'PCA', 'FNP', 'extourne', 'dotation', 'provision'] as $jargon) {
            expect(mb_strtolower($phrase))->not->toContain(mb_strtolower($jargon));
        }
    });

    test('D-1 · l’exercice courant est affiché en tête, avec un lien pour en changer', function () {
        $composant = Livewire::test(ProvisionIndex::class);

        expect(ecranRole($composant, 'exercice'))->toContain('Exercice 2025-2026');
        expect(ecranNoeud($composant, '//a[@data-role="changer-exercice"]')->getAttribute('href'))->toBe(route('exercices.changer'));
    });

    test('D-1 · l’écran suit le sélecteur d’exercice global : sur 2026-2027, les lignes de 2025-2026 disparaissent', function () {
        ['ligne' => $ligne] = ecranCasReel();

        expect(ecranCellule(Livewire::test(ProvisionIndex::class), 'a-rattacher', $ligne->id, 'piece'))->not->toBeNull();

        session(['exercice_actif' => 2026]);
        $composant = Livewire::test(ProvisionIndex::class);

        expect(ecranRole($composant, 'exercice'))->toContain('Exercice 2026-2027');
        expect(ecranCellule($composant, 'a-rattacher', $ligne->id, 'piece'))->toBeNull();
    });
});

// ── 6.1 · La zone « À rattacher » ────────────────────────────────────────────

describe('Zone « À rattacher »', function () {
    test('elle liste le cas réel — 706B, 175 €, opération « EquiThe FE sept 2026 », tiers — avec toutes ses colonnes', function () {
        ['ligne' => $ligne] = ecranCasReel();
        $composant = Livewire::test(ProvisionIndex::class);
        $cellule = fn (string $col): ?DOMNode => ecranCellule($composant, 'a-rattacher', (int) $ligne->id, $col);

        expect(ecranTexte($cellule('date')))->toBe('08/04/2026');
        expect($cellule('date')->getAttribute('data-sort'))->toBe('2026-04-08');
        expect(ecranTexte($cellule('piece')))->toBe('R-2026-0122');
        expect(ecranTexte($cellule('compte')))->toBe('706B Parcours thérapeutiques');
        expect(ecranTexte($cellule('libelle')))->toBe('Parcours thérapeutiques — Claire Durand');
        expect(ecranTexte($cellule('tiers')))->toBe('Claire DURAND');
        expect(ecranTexte($cellule('operation')))->toBe('EquiThe FE sept 2026 du 18/09/2026 au 18/06/2027');
        expect(ecranTexte($cellule('montant')))->toBe('175,00 €');
        expect($cellule('montant')->getAttribute('data-sort'))->toBe('175.00');

        $bouton = ecranNoeud($composant, sprintf('//button[@data-action="constater"][@data-ligne-id="%d"]', $ligne->id));
        expect($bouton)->not->toBeNull();
        expect(ecranTexte($bouton))->toBe('Constater d’avance');
        expect($bouton->hasAttribute('disabled'))->toBeFalse();
    });

    test('sans candidate, la zone le dit, et elle ne présente aucun tableau', function () {
        $composant = Livewire::test(ProvisionIndex::class);

        expect(ecranNombreDeNoeuds($composant, '//*[@data-zone="a-rattacher"]//table'))->toBe(0);
        expect(ecranTexte(ecranNoeud($composant, '//*[@data-zone="a-rattacher"]//*[@data-role="vide"]')))->toContain('Aucune ligne à rattacher');
    });

    test('une ligne rattachée sort de la zone et entre dans « Rattachements de l’exercice »', function () {
        ['ligne' => $ligne] = ecranCasReel();
        ecranRattacher($ligne);
        $composant = Livewire::test(ProvisionIndex::class);

        expect(ecranCellule($composant, 'a-rattacher', (int) $ligne->id, 'piece'))->toBeNull();
        expect(ecranNombreDeNoeuds($composant, '//*[@data-zone="rattachements"]//tr[@data-provision-id]'))->toBe(1);
    });

    test('les colonnes se trient côté client : en-têtes triables, dates en ISO et nombres purs dans data-sort', function () {
        ['ligne' => $ligne] = ecranCasReel();
        $composant = Livewire::test(ProvisionIndex::class);

        expect(ecranNombreDeNoeuds($composant, '//*[@data-zone="a-rattacher"]//table[@data-triable]/thead//th[@data-tri]'))->toBe(7);
        expect(ecranCellule($composant, 'a-rattacher', (int) $ligne->id, 'date')->getAttribute('data-sort'))->toMatch('/^\d{4}-\d{2}-\d{2}$/');
        expect(ecranCellule($composant, 'a-rattacher', (int) $ligne->id, 'montant')->getAttribute('data-sort'))->toMatch('/^\d+\.\d{2}$/');
        // Les en-têtes de tableau portent le style du projet.
        expect(ecranNoeud($composant, '//*[@data-zone="a-rattacher"]//thead')->getAttribute('style'))
            ->toBe('--bs-table-bg:#3d5473;--bs-table-border-color:#4d6880');
    });

    test('la page porte le script de tri, un écouteur par délégation sur les tableaux triables', function () {
        $reponse = $this->get(route('exercices.provisions'));

        $reponse->assertSee('th[data-tri]', false);
        $reponse->assertSee('table[data-triable]', false);
    });
});

describe('Requêtes', function () {
    test('le nombre de requêtes ne dépend pas du nombre de lignes affichées : aucune lecture ligne par ligne', function () {
        $compter = function (): int {
            $n = 0;
            DB::listen(function () use (&$n): void {
                $n++;
            });
            Livewire::test(ProvisionIndex::class)->set('recherche', 'Stage');

            return $n;
        };

        $une = ecranLigne(['libelle' => 'Stage 1', 'operation' => ecranOperation(), 'tiers' => Tiers::factory()->create(['association_id' => (int) TenantContext::currentId()])]);
        ecranRattacher($une);
        ecranLigneVentilee();
        $avecUneLigne = $compter();

        foreach (range(2, 5) as $i) {
            $ligne = ecranLigne(['libelle' => "Stage {$i}", 'operation' => ecranOperation(), 'tiers' => Tiers::factory()->create(['association_id' => (int) TenantContext::currentId()])]);
            ecranRattacher($ligne);
            ecranLigneVentilee();
            ecranLigne(['libelle' => "Stage libre {$i}", 'operation' => ecranOperation()]);
        }

        // Les écritures de plus (rattachements, écartées, candidates, résultats) ne coûtent aucune requête de plus.
        expect(Provision::count())->toBe(5);
        expect($compter())->toBe($avecUneLigne);
    });
});

// ── 6.3 · La modale ──────────────────────────────────────────────────────────

describe('Modale « Constater d’avance »', function () {
    test('elle s’ouvre sur la ligne choisie avec le cas en clair et la phrase du § 6.3, pour le cas réel', function () {
        ['ligne' => $ligne] = ecranCasReel();
        $composant = Livewire::test(ProvisionIndex::class)->call('ouvrir', $ligne->id);

        $modale = $composant->viewData('modale');
        expect($modale['refus'])->toBeNull();
        expect($modale['cas'])->toBe(CasProvision::ProduitConstateDavance);
        expect($modale['phrase'])->toBe("les 175,00 € encaissés le 08/04/2026 seront retirés du résultat 2025\u{2011}2026 et reconnus sur 2026\u{2011}2027.");

        expect(ecranRole($composant, 'modale-phrase'))
            ->toBe("Produit constaté d’avance — les 175,00 € encaissés le 08/04/2026 seront retirés du résultat 2025\u{2011}2026 et reconnus sur 2026\u{2011}2027.");
    });

    test('pour une charge, la phrase dit « dépensés » et le cas « Charge constatée d’avance »', function () {
        $ligne = ecranLigne(['classe' => 6, 'montant' => 90.0, 'operation' => ecranOperation()]);
        $composant = Livewire::test(ProvisionIndex::class)->call('ouvrir', $ligne->id);

        expect(ecranRole($composant, 'modale-phrase'))
            ->toBe("Charge constatée d’avance — les 90,00 € dépensés le 08/04/2026 seront retirés du résultat 2025\u{2011}2026 et reconnus sur 2026\u{2011}2027.");
    });

    test('ce qui est hérité est en lecture seule : compte, opération, séance, tiers, montant, date de pièce', function () {
        ['ligne' => $ligne] = ecranCasReel(seance: 3);
        $composant = Livewire::test(ProvisionIndex::class)->call('ouvrir', $ligne->id);
        $champ = fn (string $nom): ?string => ecranTexte(ecranNoeud($composant, sprintf('//*[@data-role="modale-origine"]//dd[@data-champ="%s"]', $nom)));

        expect($champ('compte'))->toBe('706B Parcours thérapeutiques');
        expect($champ('operation'))->toBe('EquiThe FE sept 2026 (du 18/09/2026 au 18/06/2027)');
        expect($champ('seance'))->toBe('3');
        expect($champ('tiers'))->toBe('Claire DURAND');
        expect($champ('montant'))->toBe('175,00 €');
        expect($champ('date-piece'))->toBe('08/04/2026 · pièce R-2026-0122');

        // Rien de cela n'est un champ de saisie : seules les notes et le justificatif le sont.
        $saisies = ecranDom($composant->html())->query('//*[@data-role="modale"]//*[self::input or self::textarea or self::select]');
        expect($saisies->length)->toBe(2);
    });

    test('une ligne sans opération, séance ni tiers l’affiche en tirets, sans erreur', function () {
        $ligne = ecranLigne(['piece_libelle' => 'Sans tiers']);
        $composant = Livewire::test(ProvisionIndex::class)->call('ouvrir', $ligne->id);
        $champ = fn (string $nom): ?string => ecranTexte(ecranNoeud($composant, sprintf('//*[@data-role="modale-origine"]//dd[@data-champ="%s"]', $nom)));

        expect($champ('operation'))->toBe('—');
        expect($champ('seance'))->toBe('—');
        expect($champ('tiers'))->toBe('—');
    });

    test('le montant n’est pas saisissable : aucune propriété, aucun champ, et le rattachement porte la ligne entière', function () {
        ['ligne' => $ligne] = ecranCasReel();
        $composant = Livewire::test(ProvisionIndex::class)->call('ouvrir', $ligne->id);

        expect(property_exists($composant->instance(), 'montant'))->toBeFalse();
        expect(fn () => $composant->set('montant', '1.00'))->toThrow(PublicPropertyNotFoundException::class);
        $composant->assertDontSeeHtml('wire:model="montant"');
        $composant->assertDontSeeHtml('name="montant"');

        $composant->call('confirmer');

        expect((float) Provision::sole()->montant)->toBe(175.0);
    });

    test('l’aperçu montre les deux écritures du cas réel : 706B au débit puis 487 au crédit au 31/08/2026, l’inverse au 01/09/2026', function () {
        ['ligne' => $ligne] = ecranCasReel();
        $composant = Livewire::test(ProvisionIndex::class)->call('ouvrir', $ligne->id);
        $apercu = collect($composant->viewData('modale')['apercu'])->keyBy('cle');

        $lignes = fn (string $cle): array => array_map(
            fn (array $l): array => [$l['numero_pcg'], $l['debit'], $l['credit']],
            $apercu[$cle]['lignes'],
        );

        expect($apercu->keys()->all())->toBe(['dotation', 'extourne']);
        expect($apercu['dotation']['date']->toDateString())->toBe('2026-08-31');
        expect($lignes('dotation'))->toBe([['706B', 175.0, 0.0], ['487', 0.0, 175.0]]);
        expect($apercu['extourne']['date']->toDateString())->toBe('2026-09-01');
        expect($lignes('extourne'))->toBe([['487', 175.0, 0.0], ['706B', 0.0, 175.0]]);

        // Et le HTML le dit : quatre lignes, chacune avec sa date, son compte, son débit et son crédit.
        foreach ([
            ['2026-08-31', '706B', '175.00', '0.00'], ['2026-08-31', '487', '0.00', '175.00'],
            ['2026-09-01', '487', '175.00', '0.00'], ['2026-09-01', '706B', '0.00', '175.00'],
        ] as [$date, $compte, $debit, $credit]) {
            expect(ecranNombreDeNoeuds($composant, sprintf(
                '//*[@data-role="apercu"]//tr[@data-date="%s"][@data-compte="%s"][@data-debit="%s"][@data-credit="%s"]',
                $date, $compte, $debit, $credit,
            )))->toBe(1);
        }
        expect(ecranNombreDeNoeuds($composant, '//*[@data-role="apercu"]//tbody/tr'))->toBe(4);
    });

    test('l’aperçu laisse vide le côté inutilisé d’une écriture, et nomme les comptes', function () {
        ['ligne' => $ligne] = ecranCasReel();
        $composant = Livewire::test(ProvisionIndex::class)->call('ouvrir', $ligne->id);

        $dom = ecranDom($composant->html());
        $cellules = $dom->query('.//td', $dom->query('//*[@data-ecriture="dotation"]//tr[@data-compte="706B"]')->item(0));

        expect(ecranTexte($cellules->item(0)))->toBe('706B Parcours thérapeutiques');
        expect(ecranTexte($cellules->item(1)))->toBe('175,00 €');
        expect(ecranTexte($cellules->item(2)))->toBe('');
        expect(ecranTexte(ecranNoeud($composant, '//*[@data-ecriture="dotation"]//tr[@data-compte="487"]')))->toContain('Produits constatés d\'avance');
    });

    test('l’aperçu reprend le libellé que le générateur écrira, avec le cas en toutes lettres', function () {
        ['ligne' => $ligne] = ecranCasReel();
        $composant = Livewire::test(ProvisionIndex::class)->call('ouvrir', $ligne->id);
        $apercu = collect($composant->viewData('modale')['apercu'])->keyBy('cle');

        expect($apercu['dotation']['libelle'])->toBe('Rattachement — produit constaté d’avance : Parcours thérapeutiques — Claire Durand');
        expect($apercu['extourne']['libelle'])->toBe('Extourne — produit constaté d’avance : Parcours thérapeutiques — Claire Durand');
    });

    test('fermer la modale ne crée rien', function () {
        ['ligne' => $ligne] = ecranCasReel();
        $composant = Livewire::test(ProvisionIndex::class)->call('ouvrir', $ligne->id)->set('notes', 'brouillon');

        $composant->call('fermer');

        expect($composant->viewData('modale'))->toBeNull();
        expect($composant->get('notes'))->toBe('');
        expect(Provision::count())->toBe(0);
    });

    test('la ligne ouverte est verrouillée : un client ne la change pas', function () {
        ['ligne' => $ligne] = ecranCasReel();
        $composant = Livewire::test(ProvisionIndex::class)->call('ouvrir', $ligne->id);

        expect(fn () => $composant->set('ligneId', (int) $ligne->id + 1))->toThrow(CannotUpdateLockedPropertyException::class);
    });
});

// ── La modale : la valider, et ce qu'elle refuse ─────────────────────────────

describe('Valider le rattachement', function () {
    test('confirmer crée le rattachement avec ses notes et son justificatif, et la ligne passe dans « Rattachements de l’exercice »', function () {
        ['ligne' => $ligne, 'operation' => $operation] = ecranCasReel();
        $pdf = UploadedFile::fake()->create('justificatif.pdf', 120, 'application/pdf');

        $composant = Livewire::test(ProvisionIndex::class)
            ->call('ouvrir', $ligne->id)
            ->set('notes', 'Parcours de septembre')
            ->set('pieceJointe', $pdf)
            ->call('confirmer')
            ->assertHasNoErrors();

        $provision = Provision::sole();
        expect((int) $provision->transaction_ligne_id)->toBe((int) $ligne->id);
        expect((int) $provision->exercice)->toBe(2025);
        expect((float) $provision->montant)->toBe(175.0);
        expect($provision->notes)->toBe('Parcours de septembre');
        expect((int) $provision->saisi_par)->toBe((int) $this->user->id);
        expect($provision->piece_jointe_path)->toBe('piece-jointe.pdf');
        expect($provision->piece_jointe_nom)->toBe('justificatif.pdf');
        expect($provision->piece_jointe_mime)->toBe('application/pdf');
        Storage::disk('local')->assertExists('associations/'.$this->association->id.'/provisions/'.$provision->id.'/piece-jointe.pdf');
        expect(Transaction::where('provision_id', $provision->id)->count())->toBe(2);

        // La modale est fermée, le message dit ce qui s'est passé.
        expect($composant->viewData('modale'))->toBeNull();
        expect(ecranRole($composant, 'message'))->toContain('Écriture de rattachement enregistrée');

        // La ligne a quitté la détection ; elle est dans les rattachements, avec toutes ses colonnes.
        expect(ecranCellule($composant, 'a-rattacher', (int) $ligne->id, 'piece'))->toBeNull();
        $cellule = fn (string $col): ?DOMNode => ecranCellule($composant, 'rattachements', (int) $provision->id, $col, 'data-provision-id');

        expect(ecranTexte($cellule('cas')))->toBe('Produit constaté d’avance');
        expect(ecranTexte($cellule('origine')))->toBe('R-2026-0122');
        expect(ecranTexte($cellule('compte')))->toBe('706B Parcours thérapeutiques');
        expect(ecranTexte($cellule('libelle')))->toBe('Parcours thérapeutiques — Claire Durand');
        expect(ecranTexte($cellule('operation')))->toBe($operation->nom);
        expect(ecranTexte($cellule('montant')))->toBe('175,00 €');
        expect($cellule('montant')->getAttribute('data-sort'))->toBe('175.00');
        expect(ecranTexte($cellule('piece-jointe')))->toBe('justificatif.pdf');
        expect(ecranTexte($cellule('auteur')))->toBe('Mireille Trésorière');
        expect(ecranTexte($cellule('saisie')))->toBe('15/01/2026');
        expect(ecranTexte(ecranNoeud($composant, sprintf('//button[@data-action="retirer"][@data-provision-id="%d"]', $provision->id))))->toBe('Retirer');
    });

    test('l’origine d’un rattachement est un numéro de pièce, jamais un lien : aucun lien mort', function () {
        ['ligne' => $ligne] = ecranCasReel();
        $provision = ecranRattacher($ligne);
        $composant = Livewire::test(ProvisionIndex::class);

        expect(ecranNombreDeNoeuds($composant, sprintf('//*[@data-zone="rattachements"]//tr[@data-provision-id="%d"]/td[@data-col="origine"]//a', $provision->id)))->toBe(0);
    });

    test('un justificatif d’un autre type ou trop lourd est refusé, et rien n’est créé', function (string $nom, int $taille, string $type, string $message) {
        ['ligne' => $ligne] = ecranCasReel();

        Livewire::test(ProvisionIndex::class)
            ->call('ouvrir', $ligne->id)
            ->set('pieceJointe', UploadedFile::fake()->create($nom, $taille, $type))
            ->call('confirmer')
            ->assertHasErrors('pieceJointe')
            ->assertSee($message);

        expect(Provision::count())->toBe(0);
    })->with([
        'un exécutable' => ['virus.exe', 10, 'application/x-msdownload', 'Le justificatif doit être un fichier PDF, JPG ou PNG.'],
        'plus de 10 Mo' => ['scan.pdf', 10241, 'application/pdf', 'Le justificatif ne doit pas dépasser 10 Mo.'],
    ]);

    test('un échec d’écriture du justificatif ne défait pas le rattachement : il est signalé', function () {
        ['ligne' => $ligne] = ecranCasReel();
        $disque = Mockery::mock(Storage::disk('local'))->makePartial();
        $disque->shouldReceive('put')->andThrow(new RuntimeException('Disque plein.'));

        $composant = Livewire::test(ProvisionIndex::class)
            ->call('ouvrir', $ligne->id)
            ->set('pieceJointe', UploadedFile::fake()->create('justificatif.pdf', 10, 'application/pdf'));

        Storage::set('local', $disque);
        $composant->call('confirmer');

        $provision = Provision::sole();
        expect($provision->piece_jointe_path)->toBeNull();
        expect(Transaction::where('provision_id', $provision->id)->count())->toBe(2);
        expect(ecranRole($composant, 'message'))->toContain('mais le justificatif n’a pas pu être joint');
        expect(ecranNoeud($composant, '//*[@data-role="message"]')->getAttribute('data-type'))->toBe('warning');
    });

    test('un rattachement sans notes ni justificatif est valide', function () {
        ['ligne' => $ligne] = ecranCasReel();

        Livewire::test(ProvisionIndex::class)->call('ouvrir', $ligne->id)->call('confirmer')->assertHasNoErrors();

        $provision = Provision::sole();
        expect($provision->notes)->toBeNull();
        expect($provision->piece_jointe_path)->toBeNull();
    });

    test('CCA · une charge payée d’avance : 486 au débit, 6xx au crédit, et l’aperçu est celui des écritures écrites', function () {
        $ligne = ecranLigne(['classe' => 6, 'montant' => 90.0, 'operation' => ecranOperation()]);
        $composant = Livewire::test(ProvisionIndex::class)->call('ouvrir', $ligne->id);
        $modale = $composant->viewData('modale');

        expect($modale['cas'])->toBe(CasProvision::ChargeConstateeDavance);
        expect(collect($modale['apercu'][0]['lignes'])->map(fn (array $l): array => [$l['numero_pcg'], $l['debit'], $l['credit']])->all())
            ->toBe([['486', 90.0, 0.0], [$ligne->compte->numero_pcg, 0.0, 90.0]]);

        $composant->call('confirmer');

        expect(Provision::sole()->cas())->toBe(CasProvision::ChargeConstateeDavance);
    });

    test('D-3 · l’aperçu est exactement ce qui s’écrit : date, compte, débit, crédit et libellé, ligne par ligne, PCA comme CCA', function (int $classe, float $montant) {
        $ligne = ecranLigne(['classe' => $classe, 'montant' => $montant, 'operation' => ecranOperation(), 'libelle' => 'Stage de septembre']);
        $composant = Livewire::test(ProvisionIndex::class)->call('ouvrir', $ligne->id);

        $apercu = ecranEcrituresApercues($composant->viewData('modale'));
        expect($apercu)->toHaveCount(4);

        $composant->call('confirmer');
        $ecrit = ecranEcrituresEcrites(Provision::sole());

        expect($ecrit)->toHaveCount(4);
        expect($apercu)->toBe($ecrit);
    })->with([
        'PCA' => [7, 175.0],
        'CCA' => [6, 90.0],
        'PCA, centimes' => [7, 33.33],
    ]);

    test('une ligne disparue entre l’ouverture et la validation : le refus du service s’affiche dans la modale, sans 500', function () {
        ['ligne' => $ligne] = ecranCasReel();
        $composant = Livewire::test(ProvisionIndex::class)->call('ouvrir', $ligne->id);

        // Entre l'ouverture et la validation, la ligne disparaît (sa pièce est supprimée).
        $ligne->transaction->delete();
        $composant->call('confirmer');

        expect(ecranRole($composant, 'modale-erreur'))->not->toBeNull();
        expect(Provision::count())->toBe(0);
    });

    test('ModelNotFoundException n’est pas avalée : un compte 487 absent est un défaut de configuration, pas un refus', function () {
        ['ligne' => $ligne] = ecranCasReel();
        Compte::where('numero_pcg', '487')->forceDelete();

        $composant = Livewire::test(ProvisionIndex::class)->call('ouvrir', $ligne->id);

        // L'aperçu s'affiche sans l'intitulé du compte absent ; c'est le service qui échoue, et l'erreur remonte.
        expect($composant->viewData('modale')['apercu'])->not->toBeNull();
        expect(fn () => $composant->call('confirmer'))->toThrow(ModelNotFoundException::class);
        expect(Provision::count())->toBe(0);
    });
});

// ── 6.3 · Les refus : la modale juge la ligne par le service ─────────────────

/**
 * Une ligne refusée de chaque forme, pour la modale. Chaque fabrique rend la ligne ; le motif attendu est
 * celui de RattachementExerciceService::motifDeRefus(), jamais un texte écrit à part.
 */
dataset('ecran_lignes_refusees', [
    'sens inverse — 709A au débit (AC-5)' => [function (): TransactionLigne {
        return ecranLigne([
            'compte' => ecranCompte('709A', 'Gratuités accordées'), 'montant' => 50.0, 'debit' => 50.0, 'credit' => 0.0,
            'operation' => ecranOperation(),
        ]);
    }, 'est de sens inverse'],
    'ventilée par affectations (AC-6)' => [function (): TransactionLigne {
        $ligne = ecranLigne(['montant' => 200.0]);
        foreach ([100.0, 100.0] as $part) {
            TransactionLigneAffectation::create(['transaction_ligne_id' => $ligne->id, 'operation_id' => ecranOperation()->id, 'montant' => $part]);
        }

        return $ligne;
    }, 'est ventilée sur des opérations'],
    'écriture d’inventaire — journal OD (AC-17)' => [function (): TransactionLigne {
        return ecranLigne(['journal' => JournalComptable::Od, 'operation' => ecranOperation()]);
    }, 'écriture d’inventaire'],
    'annulation d’une autre pièce (AC-17)' => [function (): TransactionLigne {
        return ecranLigne(['type_ecriture' => 'extourne', 'operation' => ecranOperation()]);
    }, 'annule une autre pièce'],
    'pièce annulée avant la fin de son exercice (AC-17)' => [function (): TransactionLigne {
        $origine = test()->ligneOrigineEquilibree(7, 175.0, ModePaiement::Virement, operationId: (int) ecranOperation()->id);
        $piece = Transaction::findOrFail($origine->transaction_id);
        app(TransactionExtourneService::class)->extourner($piece, ExtournePayload::fromOrigine($piece, ['date' => '2026-06-30']));

        return $origine;
    }, 'annulée avant la fin de son exercice'],
    'déjà rattachée (AC-13)' => [function (): TransactionLigne {
        $ligne = ecranLigne(['operation' => ecranOperation()]);
        ecranRattacher($ligne);

        return $ligne;
    }, 'déjà rattachée'],
]);

describe('Modale · une ligne refusée', function () {
    test('elle affiche le motif exact du service, sans aperçu et sans bouton de validation', function (Closure $fabrique, string $extrait) {
        $ligne = $fabrique();
        $motif = app(RattachementExerciceService::class)->motifDeRefus($ligne->fresh());

        expect($motif)->toBeString()->toContain($extrait);

        $composant = Livewire::test(ProvisionIndex::class)->call('ouvrir', $ligne->id);

        expect($composant->viewData('modale')['refus'])->toBe($motif);
        expect($composant->viewData('modale')['apercu'])->toBeNull();
        expect(ecranRole($composant, 'modale-refus'))->toBe($motif);
        expect(ecranNombreDeNoeuds($composant, '//*[@data-role="apercu"]'))->toBe(0);
        expect(ecranNombreDeNoeuds($composant, '//*[@data-role="modale-phrase"]'))->toBe(0);
        expect(ecranNombreDeNoeuds($composant, '//button[@data-action="valider"]'))->toBe(0);
    })->with('ecran_lignes_refusees');

    test('un appel forcé à confirmer est refusé par le service, avec le même motif, et rien n’est écrit', function (Closure $fabrique, string $extrait) {
        $ligne = $fabrique();
        $motif = app(RattachementExerciceService::class)->motifDeRefus($ligne->fresh());
        $avant = Provision::count();

        $composant = Livewire::test(ProvisionIndex::class)->call('ouvrir', $ligne->id)->call('confirmer');

        expect(ecranRole($composant, 'modale-erreur'))->toBe($motif);
        expect(Provision::count())->toBe($avant);
    })->with('ecran_lignes_refusees');

    test('la ligne n’est jugée qu’une fois, pour la ligne choisie : la recherche ne juge aucun de ses résultats', function () {
        foreach (['Stage A', 'Stage B', 'Stage C'] as $libelle) {
            ecranLigne(['libelle' => $libelle]);
        }
        $ligne = ecranLigne(['libelle' => 'Stage D']);

        // L'estRattachee() de motifDeRefus() : une requête sur `provisions` par ligne, sans alias.
        $motif = '/from [`"]transaction_ligne_affectations[`"] where/i';
        $composant = null;

        $pendantLaRecherche = ecranRequetes(function () use (&$composant): void {
            $composant = Livewire::test(ProvisionIndex::class)->set('recherche', 'Stage');
        }, $motif);
        expect($composant->viewData('resultatsRecherche'))->toHaveCount(4);
        expect($pendantLaRecherche)->toBe([]);

        // Ouvrir la modale juge la ligne choisie : une fois.
        $pendantLOuverture = ecranRequetes(fn () => $composant->call('ouvrir', $ligne->id), $motif);
        expect($pendantLOuverture)->toHaveCount(1);
    });
});

// ── D-4 · La recherche libre ─────────────────────────────────────────────────

describe('Recherche libre', function () {
    test('elle trouve une ligne par son numéro de pièce, son libellé ou son montant', function (string $terme) {
        $cherchee = ecranLigne(['numero_piece' => 'FAC-2026-0042', 'libelle' => 'Stage équitation', 'montant' => 1234.5]);
        ecranLigne(['numero_piece' => 'FAC-2026-0099', 'libelle' => 'Cotisation', 'montant' => 20.0]);

        $composant = Livewire::test(ProvisionIndex::class)->set('recherche', $terme);

        expect($composant->viewData('resultatsRecherche')->pluck('id')->map(fn ($id): int => (int) $id)->all())->toBe([(int) $cherchee->id]);
        expect(ecranTexte(ecranCellule($composant, 'recherche', (int) $cherchee->id, 'piece')))->toBe('FAC-2026-0042');
    })->with([
        'numéro de pièce' => ['0042'],
        'libellé de la ligne' => ['équitation'],
        'montant entier avec décimales (virgule)' => ['1234,50'],
        'montant à l’anglaise' => ['1234.5'],
        'montant avec séparateur de milliers' => ['1 234,50'],
    ]);

    test('elle trouve aussi par le libellé de la pièce', function () {
        $ligne = ecranLigne(['libelle' => 'Ligne', 'piece_libelle' => 'HelloAsso — Stage de printemps']);

        $composant = Livewire::test(ProvisionIndex::class)->set('recherche', 'Stage de printemps');

        expect($composant->viewData('resultatsRecherche')->pluck('id')->map(fn ($id): int => (int) $id)->all())->toBe([(int) $ligne->id]);
    });

    test('chaque résultat montre sa date, sa pièce, son compte, son libellé et son montant, et propose « Constater d’avance »', function () {
        $ligne = ecranLigne(['numero_piece' => 'FAC-2026-0042', 'libelle' => 'Stage équitation', 'montant' => 1234.5, 'compte' => ecranCompte('706B', 'Parcours thérapeutiques')]);
        $composant = Livewire::test(ProvisionIndex::class)->set('recherche', 'équitation');
        $cellule = fn (string $col): ?DOMNode => ecranCellule($composant, 'recherche', (int) $ligne->id, $col);

        expect(ecranTexte($cellule('date')))->toBe('08/04/2026');
        expect($cellule('date')->getAttribute('data-sort'))->toBe('2026-04-08');
        expect(ecranTexte($cellule('piece')))->toBe('FAC-2026-0042');
        expect(ecranTexte($cellule('compte')))->toBe('706B Parcours thérapeutiques');
        expect(ecranTexte($cellule('libelle')))->toBe('Stage équitation');
        expect(ecranTexte($cellule('montant')))->toBe('1 234,50 €');
        expect($cellule('montant')->getAttribute('data-sort'))->toBe('1234.50');

        // Le bouton ouvre LA MÊME modale.
        $composant->call('ouvrir', $ligne->id);
        expect($composant->viewData('modale')['ligne']->id)->toBe($ligne->id);
        expect(ecranNombreDeNoeuds($composant, '//button[@data-action="valider"]'))->toBe(1);
    });

    test('elle ne cherche que dans l’exercice : le dernier jour de N est trouvé, le premier de N+1 et le dernier de N-1 non', function (string $date, bool $trouvee) {
        ecranLigne(['date' => $date, 'libelle' => 'Frontière']);

        $composant = Livewire::test(ProvisionIndex::class)->set('recherche', 'Frontière');

        expect($composant->viewData('resultatsRecherche'))->toHaveCount($trouvee ? 1 : 0);
    })->with([
        'premier jour de N (01/09/2025)' => ['2025-09-01', true],
        'dernier jour de N (31/08/2026), que whereBetween perdrait sous SQLite' => ['2026-08-31', true],
        'dernier jour de N-1 (31/08/2025)' => ['2025-08-31', false],
        'premier jour de N+1 (01/09/2026)' => ['2026-09-01', false],
    ]);

    test('elle ne rend que des lignes de charge ou de produit : une contrepartie 411 ou 401 n’est pas proposée', function () {
        $origine = $this->ligneOrigineEquilibree(7, 175.0, ModePaiement::Virement);

        $composant = Livewire::test(ProvisionIndex::class)->set('recherche', 'Contrepartie');
        expect($composant->viewData('resultatsRecherche'))->toHaveCount(0);

        $composant->set('recherche', 'Ligne d’origine');
        expect($composant->viewData('resultatsRecherche')->pluck('id')->map(fn ($id): int => (int) $id)->all())->toBe([(int) $origine->id]);
    });

    test('AC-11 · elle ne rend jamais les lignes des écritures d’un rattachement : ni sa dotation, ni son extourne', function () {
        ecranRattacher(ecranLigne(['libelle' => 'Stage de septembre', 'operation' => ecranOperation()]));

        $composant = Livewire::test(ProvisionIndex::class)->set('recherche', 'Stage de septembre');

        // Seule l'origine est rendue ; la dotation du 31/08/2026, de l'exercice, porte le même libellé.
        expect($composant->viewData('resultatsRecherche'))->toHaveCount(1);
        expect($composant->viewData('resultatsRecherche')->first()->transaction->provision_id)->toBeNull();
    });

    test('elle ne rend ni une ligne dont la pièce est supprimée, ni une ligne supprimée', function () {
        $piece = ecranLigne(['libelle' => 'Pièce supprimée']);
        $piece->transaction->delete();
        $ligne = ecranLigne(['libelle' => 'Ligne supprimée']);
        $ligne->delete();

        $composant = Livewire::test(ProvisionIndex::class);

        expect($composant->set('recherche', 'Pièce supprimée')->viewData('resultatsRecherche'))->toHaveCount(0);
        expect($composant->set('recherche', 'Ligne supprimée')->viewData('resultatsRecherche'))->toHaveCount(0);
    });

    test('elle rend aussi les lignes que la modale refusera : la recherche liste, la modale juge', function () {
        $refusee = ecranLigne([
            'compte' => ecranCompte('709A', 'Gratuités accordées'), 'montant' => 50.0, 'debit' => 50.0, 'credit' => 0.0,
            'libelle' => 'Gratuité du stage',
        ]);

        $composant = Livewire::test(ProvisionIndex::class)->set('recherche', 'Gratuité');
        expect($composant->viewData('resultatsRecherche')->pluck('id')->map(fn ($id): int => (int) $id)->all())->toBe([(int) $refusee->id]);

        $composant->call('ouvrir', $refusee->id);
        expect($composant->viewData('modale')['refus'])->toContain('de sens inverse');
        expect(ecranNombreDeNoeuds($composant, '//button[@data-action="valider"]'))->toBe(0);
    });

    test('au plus 20 lignes, et un mot quand il y en a d’autres', function (int $nombre, bool $tronquee) {
        foreach (range(1, $nombre) as $i) {
            ecranLigne(['libelle' => "Séance {$i}", 'date' => '2026-04-08']);
        }

        $composant = Livewire::test(ProvisionIndex::class)->set('recherche', 'Séance');

        expect($composant->viewData('resultatsRecherche'))->toHaveCount(min($nombre, 20));
        expect(ecranNombreDeNoeuds($composant, '//*[@data-zone="recherche"]//tbody/tr'))->toBe(min($nombre, 20));
        expect(ecranNombreDeNoeuds($composant, '//*[@data-role="recherche-tronquee"]'))->toBe($tronquee ? 1 : 0);
    })->with([
        '20 lignes : toutes' => [20, false],
        '21 lignes : les 20 premières' => [21, true],
        '3 lignes' => [3, false],
    ]);

    test('les résultats sont rendus par date, de la plus ancienne à la plus récente', function () {
        $recent = ecranLigne(['libelle' => 'Stage', 'date' => '2026-06-10']);
        $ancien = ecranLigne(['libelle' => 'Stage', 'date' => '2025-10-02']);

        $composant = Livewire::test(ProvisionIndex::class)->set('recherche', 'Stage');

        expect($composant->viewData('resultatsRecherche')->pluck('id')->map(fn ($id): int => (int) $id)->all())
            ->toBe([(int) $ancien->id, (int) $recent->id]);
    });

    test('les jokers du terme sont cherchés tels quels : « % » ne rend que les libellés qui en contiennent un', function (string $terme, string $attendu) {
        ecranLigne(['libelle' => 'Remise 10% adhérents']);
        ecranLigne(['libelle' => 'Stage ordinaire']);
        ecranLigne(['libelle' => 'frais_divers']);

        $composant = Livewire::test(ProvisionIndex::class)->set('recherche', $terme);

        expect($composant->viewData('resultatsRecherche')->pluck('libelle')->all())->toBe([$attendu]);
    })->with([
        'pourcentage' => ['%', 'Remise 10% adhérents'],
        'tiret bas' => ['_', 'frais_divers'],
    ]);

    test('sans terme, la zone ne présente ni résultat ni message ; sans correspondance, elle le dit', function () {
        ecranLigne(['libelle' => 'Stage']);
        $composant = Livewire::test(ProvisionIndex::class);

        expect(ecranNombreDeNoeuds($composant, '//*[@data-zone="recherche"]//table'))->toBe(0);
        expect(ecranNombreDeNoeuds($composant, '//*[@data-role="recherche-vide"]'))->toBe(0);

        $composant->set('recherche', '   ');
        expect(ecranNombreDeNoeuds($composant, '//*[@data-role="recherche-vide"]'))->toBe(0);

        $composant->set('recherche', 'introuvable');
        expect(ecranRole($composant, 'recherche-vide'))->toContain('Aucune ligne');
    });

    test('elle est scopée à l’association : la ligne d’une autre association n’apparaît pas, par aucun de ses critères', function () {
        $autre = Association::factory()->create();
        TenantContext::boot($autre);
        SystemeSeeder::seed();
        $etrangere = ecranLigne(['numero_piece' => 'ZZ-9999', 'libelle' => 'Secret de l’autre', 'montant' => 777.77, 'piece_libelle' => 'Pièce secrète']);
        // Un compte de l'autre association, créé sous SON contexte : un compte créé sous un autre contexte que le sien
        // fait échouer la matérialisation de sa famille (unicité des familles hors scope tenant) — défaut connu, sans rapport.
        $compteEtranger = Compte::factory()->recette()->create(['association_id' => $autre->id, 'actif' => true]);
        TenantContext::boot($this->association);

        $composant = Livewire::test(ProvisionIndex::class);

        foreach (['ZZ-9999', 'Secret de l’autre', '777,77', 'Pièce secrète'] as $terme) {
            expect($composant->set('recherche', $terme)->viewData('resultatsRecherche'))->toHaveCount(0);
        }

        // Une ligne de notre association, dont le COMPTE est d'une autre association (donnée incohérente) : écartée aussi.
        $nous = ecranLigne(['libelle' => 'Compte étranger', 'compte' => $compteEtranger]);

        expect($composant->set('recherche', 'Compte étranger')->viewData('resultatsRecherche'))->toHaveCount(0);
        expect($etrangere->id)->not->toBe($nous->id);
    });

    test('une ligne d’une autre association ne s’ouvre pas : message, aucune modale', function () {
        $autre = Association::factory()->create();
        TenantContext::boot($autre);
        SystemeSeeder::seed();
        $etrangere = ecranLigne(['operation' => ecranOperation(), 'libelle' => 'Secret de l’autre']);
        TenantContext::boot($this->association);

        $composant = Livewire::test(ProvisionIndex::class)->call('ouvrir', $etrangere->id);

        expect($composant->viewData('modale'))->toBeNull();
        expect($composant->get('ligneId'))->toBeNull();
        expect(ecranRole($composant, 'message'))->toBe('Cette ligne n’existe pas.');
        expect(Provision::withoutGlobalScopes()->count())->toBe(0);
    });
});

// ── D-5 · Les lignes écartées ────────────────────────────────────────────────

describe('Lignes écartées', function () {
    test('une ligne ventilée sur une opération future est listée à part, avec son motif, ses colonnes et ses opérations', function () {
        $ligne = ecranLigneVentilee();
        $composant = Livewire::test(ProvisionIndex::class);
        $cellule = fn (string $col): ?DOMNode => ecranCellule($composant, 'ecartees', (int) $ligne->id, $col);

        expect(ecranTexte($cellule('date')))->toBe('08/04/2026');
        expect(ecranTexte($cellule('piece')))->toBe('S-2026-0007');
        expect(ecranTexte($cellule('libelle')))->toBe('Subvention affectée');
        expect(ecranTexte($cellule('montant')))->toBe('200,00 €');
        expect(ecranTexte($cellule('motif')))->toBe(RattachementExerciceService::MOTIF_AFFECTATIONS);
        // Les opérations de la ventilation, de la plus ancienne à la plus récente : c'est ce qui explique la ligne.
        expect(ecranTexte($cellule('operation')))->toBe('EquiThe FE sept 2026 (à partir du 18/09/2026) Stage de Toussaint (à partir du 01/10/2026)');

        // Elle n'est ni proposée ni cachée.
        expect(ecranCellule($composant, 'a-rattacher', (int) $ligne->id, 'piece'))->toBeNull();
        expect(ecranNombreDeNoeuds($composant, '//*[@data-zone="ecartees"]//button'))->toBe(0);
    });

    test('la zone n’existe que s’il y a des lignes écartées', function () {
        ecranCasReel();

        expect(ecranNombreDeNoeuds(Livewire::test(ProvisionIndex::class), '//*[@data-zone="ecartees"]'))->toBe(0);
    });

    test('une subvention ventilée sur des opérations toutes passées n’est pas listée : elle n’a rien à décaler', function () {
        $ligne = ecranLigne(['montant' => 200.0]);
        TransactionLigneAffectation::create(['transaction_ligne_id' => $ligne->id, 'operation_id' => ecranOperation('2025-10-01', '2026-03-01', 'Stage passé')->id, 'montant' => 200]);

        expect(ecranNombreDeNoeuds(Livewire::test(ProvisionIndex::class), '//*[@data-zone="ecartees"]'))->toBe(0);
    });

    test('une opération d’une autre association, ou supprimée, n’est pas nommée dans la ventilation d’une écartée', function () {
        $ligne = ecranLigneVentilee();

        // Une troisième affectation sur l'opération d'une autre association, une quatrième sur une opération supprimée.
        $autre = Association::factory()->create();
        $etrangere = Operation::factory()->create([
            'association_id' => $autre->id, 'type_operation_id' => null, 'nom' => 'Opération étrangère',
            'date_debut' => '2026-10-10', 'date_fin' => '2027-01-10',
        ]);
        $supprimee = ecranOperation('2026-11-01', '2027-02-01', 'Opération supprimée');
        foreach ([$etrangere, $supprimee] as $operation) {
            TransactionLigneAffectation::create(['transaction_ligne_id' => $ligne->id, 'operation_id' => $operation->id, 'montant' => 10]);
        }
        $supprimee->delete();

        $texte = ecranTexte(ecranCellule(Livewire::test(ProvisionIndex::class), 'ecartees', (int) $ligne->id, 'operation'));

        expect($texte)->toContain('EquiThe FE sept 2026')->toContain('Stage de Toussaint');
        expect($texte)->not->toContain('Opération étrangère')->not->toContain('Opération supprimée');
    });

    test('les opérations de toutes les écartées sont lues en UNE requête, pas ligne par ligne', function () {
        ecranLigneVentilee();
        ecranLigneVentilee();
        ecranLigneVentilee();
        $composant = null;

        $requetes = ecranRequetes(function () use (&$composant): void {
            $composant = Livewire::test(ProvisionIndex::class);
        }, '/^select [`"]a[`"]\.[`"]transaction_ligne_id[`"]/i');

        expect(ecranNombreDeNoeuds($composant, '//*[@data-zone="ecartees"]//tbody/tr'))->toBe(3);
        expect($requetes)->toHaveCount(1);
    });
});

// ── 6.4 · Retrait ────────────────────────────────────────────────────────────

describe('Retirer un rattachement', function () {
    test('« Retirer » supprime les deux écritures et le rattachement, libère la ligne, qui revient dans la zone « À rattacher »', function () {
        ['ligne' => $ligne] = ecranCasReel();
        $provision = ecranRattacher($ligne);
        $ecritures = Transaction::where('provision_id', $provision->id)->pluck('id')->all();
        expect($ecritures)->toHaveCount(2);

        $composant = Livewire::test(ProvisionIndex::class)->call('retirer', $provision->id);

        expect(DB::table('provisions')->where('id', $provision->id)->exists())->toBeFalse();
        expect(DB::table('transactions')->whereIn('id', $ecritures)->count())->toBe(0);
        expect($ligne->fresh()->estRattachee())->toBeFalse();
        expect(ecranRole($composant, 'message'))->toContain('Rattachement retiré');
        expect(ecranCellule($composant, 'a-rattacher', (int) $ligne->id, 'piece'))->not->toBeNull();
        expect(ecranNombreDeNoeuds($composant, '//*[@data-zone="rattachements"]//tr[@data-provision-id]'))->toBe(0);
    });

    test('le retrait supprime le justificatif du rattachement', function () {
        ['ligne' => $ligne] = ecranCasReel();
        Livewire::test(ProvisionIndex::class)
            ->call('ouvrir', $ligne->id)
            ->set('pieceJointe', UploadedFile::fake()->create('justificatif.pdf', 10, 'application/pdf'))
            ->call('confirmer');
        $provision = Provision::sole();
        $chemin = 'associations/'.$this->association->id.'/provisions/'.$provision->id.'/piece-jointe.pdf';
        Storage::disk('local')->assertExists($chemin);

        Livewire::test(ProvisionIndex::class)->call('retirer', $provision->id);

        Storage::disk('local')->assertMissing($chemin);
    });

    test('« Retirer » demande confirmation par la modale Bootstrap globale : wire:confirm, jamais un confirm() natif', function () {
        ['ligne' => $ligne] = ecranCasReel();
        $provision = ecranRattacher($ligne);

        $composant = Livewire::test(ProvisionIndex::class);
        $bouton = ecranNoeud($composant, sprintf('//button[@data-action="retirer"][@data-provision-id="%d"]', $provision->id));

        expect($bouton->getAttribute('wire:confirm'))->toContain('Retirer ce rattachement ?');
        expect($composant->html())->not->toContain('confirm(');

        // La modale de confirmation est celle du gabarit, présente sur la page.
        $this->get(route('exercices.provisions'))->assertSee('id="confirmModal"', false);
    });

    test('un retrait forcé d’un rattachement d’une autre association est refusé, et l’autre rattachement reste', function () {
        $autre = Association::factory()->create();
        TenantContext::boot($autre);
        SystemeSeeder::seed();
        $provisionEtrangere = provisionPourLigne(ecranLigne(['operation' => ecranOperation()]));
        TenantContext::boot($this->association);

        $composant = Livewire::test(ProvisionIndex::class)->call('retirer', $provisionEtrangere->id);

        expect(ecranRole($composant, 'message'))->toContain('n’existe plus');
        expect(DB::table('provisions')->where('id', $provisionEtrangere->id)->exists())->toBeTrue();
    });

    test('un rattachement dont l’origine a disparu s’affiche encore, et se retire', function () {
        ['ligne' => $ligne] = ecranCasReel();
        $provision = ecranRattacher($ligne);
        $ligne->delete();

        $composant = Livewire::test(ProvisionIndex::class);

        expect(ecranTexte(ecranCellule($composant, 'rattachements', (int) $provision->id, 'origine', 'data-provision-id')))->toBe('R-2026-0122');
        expect(ecranTexte(ecranCellule($composant, 'rattachements', (int) $provision->id, 'cas', 'data-provision-id')))->toBe('Produit constaté d’avance');

        $composant->call('retirer', $provision->id);

        expect(DB::table('provisions')->where('id', $provision->id)->exists())->toBeFalse();
    });

    test('le justificatif se télécharge ; celui d’un autre rattachement, d’une autre association ou sans fichier est refusé', function () {
        ['ligne' => $ligne] = ecranCasReel();
        Livewire::test(ProvisionIndex::class)
            ->call('ouvrir', $ligne->id)
            ->set('pieceJointe', UploadedFile::fake()->create('justificatif.pdf', 10, 'application/pdf'))
            ->call('confirmer');
        $provision = Provision::sole();

        Livewire::test(ProvisionIndex::class)->call('telechargerPieceJointe', $provision->id)->assertFileDownloaded('justificatif.pdf');

        // Un rattachement sans justificatif.
        $sans = ecranRattacher(ecranLigne(['operation' => ecranOperation(), 'libelle' => 'Autre']));
        $composant = Livewire::test(ProvisionIndex::class)->call('telechargerPieceJointe', $sans->id);
        expect(ecranRole($composant, 'message'))->toBe('Ce justificatif est introuvable.');

        // Celui d'une autre association.
        $autre = Association::factory()->create();
        TenantContext::boot($autre);
        SystemeSeeder::seed();
        $etranger = provisionPourLigne(ecranLigne(['operation' => ecranOperation()]));
        $etranger->update(['piece_jointe_path' => 'piece-jointe.pdf', 'piece_jointe_nom' => 'secret.pdf', 'piece_jointe_mime' => 'application/pdf']);
        Storage::disk('local')->put($etranger->storagePath('provisions/'.$etranger->id.'/piece-jointe.pdf'), 'secret');
        TenantContext::boot($this->association);

        $composant = Livewire::test(ProvisionIndex::class)->call('telechargerPieceJointe', $etranger->id);
        expect(ecranRole($composant, 'message'))->toBe('Ce justificatif est introuvable.');
    });
});

// ── D-2 · La période fermée ──────────────────────────────────────────────────

describe('Période fermée', function () {
    test('un seul bandeau en tête, et tous les boutons « Constater d’avance » et « Retirer » sont désactivés', function (int $anneeCloturee, string $exerciceDit) {
        ['ligne' => $ligne] = ecranCasReel();
        $autre = ecranLigne(['libelle' => 'Autre', 'operation' => ecranOperation(), 'numero_piece' => 'A-1']);
        $provision = ecranRattacher($autre);
        Exercice::create(['annee' => $anneeCloturee, 'statut' => StatutExercice::Cloture]);

        $composant = Livewire::test(ProvisionIndex::class)->set('recherche', 'Autre');

        expect(ecranNombreDeNoeuds($composant, '//*[@data-role="periode-fermee"]'))->toBe(1);
        expect(ecranRole($composant, 'periode-fermee'))->toContain($exerciceDit);
        expect(ecranRole($composant, 'periode-fermee'))->toBe(app(RattachementExerciceService::class)->motifPeriodeFermee(2025));

        // Les boutons de la détection, de la recherche et des rattachements : tous désactivés, et il y en a.
        $boutons = ecranDom($composant->html())->query('//button[@data-action="constater" or @data-action="retirer"]');
        expect($boutons->length)->toBe(3);
        foreach ($boutons as $bouton) {
            expect($bouton->hasAttribute('disabled'))->toBeTrue();
        }
        expect(ecranNombreDeNoeuds($composant, sprintf('//button[@data-action="constater"][@data-ligne-id="%d"][@disabled]', $ligne->id)))->toBe(1);
        expect(ecranNombreDeNoeuds($composant, sprintf('//button[@data-action="retirer"][@data-provision-id="%d"][@disabled]', $provision->id)))->toBe(1);
    })->with([
        'N clôturé' => [2025, 'L’exercice 2025-2026 est clôturé'],
        'N+1 clôturé' => [2026, 'L’exercice 2026-2027 est clôturé'],
    ]);

    test('sans exercice clôturé, aucun bandeau et des boutons actifs', function () {
        ecranCasReel();
        $composant = Livewire::test(ProvisionIndex::class);

        expect(ecranNombreDeNoeuds($composant, '//*[@data-role="periode-fermee"]'))->toBe(0);
        expect(ecranNombreDeNoeuds($composant, '//button[@data-action="constater"][not(@disabled)]'))->toBe(1);
    });

    test('un appel forcé est refusé par le service, avec son message : ni création ni retrait', function () {
        ['ligne' => $ligne] = ecranCasReel();
        $autre = ecranRattacher(ecranLigne(['libelle' => 'Autre', 'operation' => ecranOperation()]));
        Exercice::create(['annee' => 2025, 'statut' => StatutExercice::Cloture]);

        // Création forcée : la modale s'ouvre (le client a forcé le bouton), la validation est refusée.
        $composant = Livewire::test(ProvisionIndex::class)->call('ouvrir', $ligne->id)->call('confirmer');

        expect(ecranRole($composant, 'modale-erreur'))->toContain('est clôturé');
        expect(Provision::count())->toBe(1);

        // Retrait forcé.
        $composant->call('fermer')->call('retirer', $autre->id);

        expect(ecranNoeud($composant, '//*[@data-role="message"]')->getAttribute('data-type'))->toBe('danger');
        expect(ecranRole($composant, 'message'))->toContain('est clôturé');
        expect(DB::table('provisions')->where('id', $autre->id)->exists())->toBeTrue();
    });

    test('la validation de la modale est elle aussi désactivée', function () {
        ['ligne' => $ligne] = ecranCasReel();
        Exercice::create(['annee' => 2026, 'statut' => StatutExercice::Cloture]);

        $composant = Livewire::test(ProvisionIndex::class)->call('ouvrir', $ligne->id);

        expect(ecranNoeud($composant, '//button[@data-action="valider"]')->hasAttribute('disabled'))->toBeTrue();
    });
});

// ── Les droits ───────────────────────────────────────────────────────────────

describe('Droits d’écriture', function () {
    test('un utilisateur en consultation voit l’écran, mais ses boutons sont désactivés et ses appels forcés refusés', function () {
        ['ligne' => $ligne] = ecranCasReel();
        $provision = ecranRattacher(ecranLigne(['libelle' => 'Autre', 'operation' => ecranOperation()]));

        $lecteur = User::factory()->create();
        $lecteur->associations()->attach($this->association->id, ['role' => 'consultation', 'joined_at' => now()]);
        $this->actingAs($lecteur);

        $composant = Livewire::test(ProvisionIndex::class);

        foreach (ecranDom($composant->html())->query('//button[@data-action="constater" or @data-action="retirer"]') as $bouton) {
            expect($bouton->hasAttribute('disabled'))->toBeTrue();
        }
        expect(ecranCellule($composant, 'a-rattacher', (int) $ligne->id, 'piece'))->not->toBeNull();

        $composant->call('ouvrir', $ligne->id);
        expect($composant->viewData('modale'))->toBeNull();
        expect(ecranNoeud($composant, '//*[@data-role="message"]')->getAttribute('data-type'))->toBe('danger');

        $composant->call('retirer', $provision->id);
        expect(DB::table('provisions')->where('id', $provision->id)->exists())->toBeTrue();
        expect(Provision::count())->toBe(1);
    });

    test('même une validation forcée sur une modale déjà ouverte est refusée à un utilisateur en consultation', function () {
        ['ligne' => $ligne] = ecranCasReel();
        $composant = Livewire::test(ProvisionIndex::class)->call('ouvrir', $ligne->id);

        $lecteur = User::factory()->create();
        $lecteur->associations()->attach($this->association->id, ['role' => 'consultation', 'joined_at' => now()]);
        $this->actingAs($lecteur);

        $composant->call('confirmer');

        expect(ecranRole($composant, 'modale-erreur'))->toContain('pas les droits');
        expect(Provision::count())->toBe(0);
    });

    test('un comptable peut constater et retirer', function () {
        ['ligne' => $ligne] = ecranCasReel();
        $comptable = User::factory()->create();
        $comptable->associations()->attach($this->association->id, ['role' => 'comptable', 'joined_at' => now()]);
        $this->actingAs($comptable);

        Livewire::test(ProvisionIndex::class)->call('ouvrir', $ligne->id)->call('confirmer');
        expect(Provision::count())->toBe(1);

        Livewire::test(ProvisionIndex::class)->call('retirer', Provision::sole()->id);
        expect(Provision::count())->toBe(0);
    });
});
