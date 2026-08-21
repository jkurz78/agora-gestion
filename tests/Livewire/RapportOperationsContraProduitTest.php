<?php

declare(strict_types=1);

/**
 * Contra-produit 709A « Gratuités accordées » dans le compte de résultat par
 * opérations.
 *
 * Un compte de classe 7 qui vit au DÉBIT invalide l'hypothèse implicite
 * « un produit est toujours positif ». Cette hypothèse était écrite à quatre
 * endroits distincts de la vue, chacun corrigé séparément :
 *   - $scVisibles, visibilité du COMPTE            (94e87529)
 *   - les cellules de montant réalisé              (d1212701)
 *   - $tVisible, visibilité du TIERS sous le compte (65bbf980)
 *   - la cellule tiers × opération hors combinedMode
 *
 * Les trois premiers n'avaient été vérifiés qu'à la main. Ce test rend le
 * composant pour de vrai et lit le HTML : vérifier que les DONNÉES sont bonnes
 * ne prouve rien sur l'affichage — elles l'étaient aux quatre tours.
 *
 * Les cellules de PROJECTION gardent volontairement « > 0 » : un budget
 * prévisionnel est positif par nature. Elles ne sont donc pas couvertes ici.
 */

use App\Livewire\RapportCompteResultatOperations;
use App\Models\Association;
use App\Models\Compte;
use App\Models\Operation;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Models\User;
use App\Tenant\TenantContext;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->association = Association::factory()->create();
    $this->user = User::factory()->create();
    $this->user->associations()->attach($this->association->id, ['role' => 'admin', 'joined_at' => now()]);
    TenantContext::boot($this->association);
    session(['current_association_id' => $this->association->id]);
    $this->actingAs($this->user);
    session(['exercice_actif' => 2025]);

    // Pièce du chantier 709A : produit brut 50 € au crédit du 706A, gratuité
    // 50 € au DÉBIT du 709A — même tiers, même opération, net nul.
    $this->operation = Operation::factory()->create([
        'association_id' => $this->association->id,
        'nom' => 'Parcours équi-thé',
    ]);
    $c706 = Compte::factory()->numero('706A')->create([
        'association_id' => $this->association->id,
        'intitule' => 'Formations',
    ]);
    $c709 = Compte::factory()->numero('709A')->create([
        'association_id' => $this->association->id,
        'intitule' => 'Gratuités accordées',
    ]);
    $this->beneficiaire = Tiers::factory()->create([
        'association_id' => $this->association->id,
        'nom' => 'SURPIN',
        'prenom' => 'Charles',
    ]);

    $tx = Transaction::factory()->asRecette()->create([
        'association_id' => $this->association->id,
        'date' => '2026-04-08',
        'saisi_par' => $this->user->id,
        'tiers_id' => $this->beneficiaire->id,
    ]);
    $tx->lignes()->forceDelete();

    TransactionLigne::factory()->create([
        'transaction_id' => $tx->id,
        'compte_id' => $c706->id,
        'operation_id' => (int) $this->operation->id,
        'montant' => 50.0, 'debit' => 0.0, 'credit' => 50.0,
    ]);
    TransactionLigne::factory()->create([
        'transaction_id' => $tx->id,
        'compte_id' => $c709->id,
        'operation_id' => (int) $this->operation->id,
        'montant' => 50.0, 'debit' => 50.0, 'credit' => 0.0,
    ]);
});

afterEach(function (): void {
    TenantContext::clear();
    session()->forget('exercice_actif');
});

/**
 * Rend le composant dans une combinaison de bascules et retourne le HTML.
 */
function contraProduitHtml(int $operationId, bool $parSeances, bool $parTiers, bool $parOperations): string
{
    return Livewire::test(RapportCompteResultatOperations::class)
        ->set('parSeances', $parSeances)
        ->set('parTiers', $parTiers)
        ->set('parOperations', $parOperations)
        ->set('selectedOperationIds', [$operationId])
        ->html();
}

/**
 * Extrait les cellules de la ligne <tr> du tiers sous le compte 709A. La ligne
 * du 706A porte le même tiers : on garde celle dont le total est négatif.
 */
function cellulesTiersGratuite(string $html): array
{
    preg_match_all('#<tr[^>]*>(?:(?!</tr>).)*SURPIN(?:(?!</tr>).)*</tr>#s', $html, $lignes);

    foreach ($lignes[0] as $tr) {
        preg_match_all('#<td[^>]*>(.*?)</td>#s', $tr, $cellules);
        $textes = array_map(
            fn (string $c): string => trim(html_entity_decode(strip_tags($c))),
            $cellules[1],
        );
        if (in_array('-50,00 €', $textes, true)) {
            return $textes;
        }
    }

    return [];
}

it('affiche le montant négatif du tiers dans le détail par opérations, sans séances', function (): void {
    // combinedMode = parSeances && parOperations. Avec parSeances à false, la
    // ligne tiers emprunte la branche @elseif ($parOperations) — la seule dont
    // la cellule mélangeait projection et réalisé dans un unique ternaire, et
    // gardait donc « > 0 » sur le réalisé.
    $html = contraProduitHtml((int) $this->operation->id, parSeances: false, parTiers: true, parOperations: true);

    $cellules = cellulesTiersGratuite($html);

    expect($cellules)->not->toBeEmpty('Le tiers de la gratuité doit apparaître sous le 709A.');
    // Une colonne d'opération + une colonne de total, toutes deux à -50,00 €.
    expect(array_count_values($cellules)['-50,00 €'] ?? 0)->toBe(2,
        'La colonne de l\'opération affichait « — » alors que le total affichait bien -50,00 €.');
});

it('affiche le montant négatif du tiers en mode combiné séances × opérations', function (): void {
    $html = contraProduitHtml((int) $this->operation->id, parSeances: true, parTiers: true, parOperations: true);

    expect(cellulesTiersGratuite($html))->not->toBeEmpty();
    expect(substr_count($html, '-50,00'))->toBeGreaterThan(0);
});

it('affiche le compte, son montant et son tiers négatifs en détail par tiers seul', function (): void {
    $html = contraProduitHtml((int) $this->operation->id, parSeances: false, parTiers: true, parOperations: false);

    // Les trois niveaux d'un coup : le compte est visible, sa cellule porte le
    // montant, et le tiers apparaît dessous.
    expect($html)->toContain('Gratuités accordées');
    expect($html)->toContain('-50,00');
    expect(cellulesTiersGratuite($html))->not->toBeEmpty();
});
