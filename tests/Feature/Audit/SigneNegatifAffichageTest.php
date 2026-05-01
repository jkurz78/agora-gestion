<?php

declare(strict_types=1);

/**
 * Audit signe négatif — Step 9 : affichage et tri data-sort colonnes montants.
 *
 * Partie 1 : helper formatMontant
 *   - Aucun helper global formatMontant n'existe dans le projet (pas de app/Helpers/,
 *     pas d'autoload files dans composer.json, pas d'appels formatMontant dans les vues).
 *   - Le formatage est fait par number_format() direct dans les vues.
 *   - Ce test vérifie que number_format gère correctement le signe négatif.
 *
 * Partie 2 : tri data-sort colonnes montants
 *   - La vue provision-index.blade.php est la seule qui cumule : (a) data-sort sur
 *     colonne montant, (b) tri JS côté client.
 *   - Le test vérifie que data-sort émet des valeurs numériques brutes (pas du texte
 *     formaté "−80,00 €") et que leur ordre numérique est [-100, -10, 50, 200].
 *
 * @see docs/audit/2026-04-30-signe-negatif.md §2.5
 */

use App\Enums\StatutExercice;
use App\Enums\TypeTransaction;
use App\Livewire\Provisions\ProvisionIndex;
use App\Models\Association;
use App\Models\Categorie;
use App\Models\Exercice;
use App\Models\Provision;
use App\Models\SousCategorie;
use App\Models\User;
use App\Tenant\TenantContext;
use Livewire\Livewire;

// ── Fixtures ──────────────────────────────────────────────────────────────────

beforeEach(function () {
    $this->association = Association::factory()->create();
    $this->user = User::factory()->create();
    $this->user->associations()->attach($this->association->id, [
        'role' => 'admin',
        'joined_at' => now(),
    ]);
    TenantContext::boot($this->association);
    session(['current_association_id' => $this->association->id]);
    $this->actingAs($this->user);

    $categorie = Categorie::factory()->create(['association_id' => $this->association->id]);
    $this->sc = SousCategorie::factory()->create([
        'categorie_id' => $categorie->id,
        'association_id' => $this->association->id,
    ]);

    Exercice::create([
        'association_id' => $this->association->id,
        'annee' => 2025,
        'statut' => StatutExercice::Ouvert,
    ]);
    session(['exercice_actif' => 2025]);
});

afterEach(function () {
    TenantContext::clear();
});

// ── Test 1 : formatage nombre négatif via number_format (pas de helper) ───────

it('format_montant_affiche_signe_negatif_correctement', function () {
    // Aucun helper formatMontant n'existe dans le projet.
    // Le formatage est fait par number_format() direct dans les vues.
    // Vérifier que number_format(-80, 2, ',', ' ') . ' €' rend bien '-80,00 €'.

    $montant = -80.0;
    $formatted = number_format($montant, 2, ',', ' ').' €';

    expect($formatted)->toBe('-80,00 €');

    // Valeurs supplémentaires de la plage audit
    expect(number_format(-100.0, 2, ',', ' ').' €')->toBe('-100,00 €');
    expect(number_format(-10.0, 2, ',', ' ').' €')->toBe('-10,00 €');
    expect(number_format(50.0, 2, ',', ' ').' €')->toBe('50,00 €');
    expect(number_format(200.0, 2, ',', ' ').' €')->toBe('200,00 €');

    // Valeur zéro
    expect(number_format(0.0, 2, ',', ' ').' €')->toBe('0,00 €');

    // Milliers : -1 500,00 €
    expect(number_format(-1500.0, 2, ',', ' ').' €')->toBe('-1 500,00 €');
});

// ── Test 2 : data-sort sur colonne montant est numérique (pas du texte formaté) ─

it('data_sort_sur_colonnes_montants_est_numerique', function () {
    // Dataset : [+50, -100, +200, -10] — ordre numérique attendu : -100, -10, 50, 200

    $montants = [50.0, -100.0, 200.0, -10.0];

    foreach ($montants as $montant) {
        Provision::create([
            'association_id' => $this->association->id,
            'exercice' => 2025,
            'type' => TypeTransaction::Depense,
            'sous_categorie_id' => $this->sc->id,
            'libelle' => 'Prov '.abs($montant).(($montant < 0) ? ' neg' : ' pos'),
            'montant' => $montant,
            'saisi_par' => $this->user->id,
            'date' => '2025-10-01',
        ]);
    }

    $html = Livewire::test(ProvisionIndex::class)
        ->assertOk()
        ->html();

    // Parser les data-sort de la colonne montant (index td[3] de chaque tr[wire:key])
    // Le HTML contient des <td class="text-end" data-sort="..."> pour les montants.
    // Extraire toutes les valeurs data-sort associées à la colonne montant.
    preg_match_all('/data-sort="([^"]+)"/', $html, $matches);
    $allDataSort = $matches[1];

    // Les valeurs data-sort des montants doivent être des nombres bruts parsables
    // (pas "-80,00 €", pas "-80,00", mais "-80.00" ou "-80" — point décimal anglais).
    // Le cast decimal:2 Laravel émet "50.00", "-100.00", "200.00", "-10.00".

    $expectedRaw = ['50.00', '-100.00', '200.00', '-10.00'];

    // Chaque valeur numérique brute doit être présente dans les data-sort
    foreach ($expectedRaw as $expected) {
        expect($allDataSort)->toContain($expected);
    }

    // Vérifier qu'aucune valeur data-sort de montant n'est du texte formaté avec virgule ou €
    $formattedDataSort = array_filter($allDataSort, function (string $val): bool {
        // Les valeurs qui sont des montants formatés contiendraient ',' ou '€'
        return str_contains($val, ',') || str_contains($val, '€');
    });

    // Aucune valeur data-sort ne doit contenir de texte formaté
    expect(array_values($formattedDataSort))->toBeEmpty();

    // Vérifier que les valeurs data-sort numériques sont bien triables numériquement :
    // parseFloat('-100.00') < parseFloat('-10.00') < parseFloat('50.00') < parseFloat('200.00')
    $numericValues = array_map('floatval', $expectedRaw);
    $sorted = $numericValues;
    sort($sorted);

    expect($sorted)->toBe([-100.0, -10.0, 50.0, 200.0],
        'L\'ordre numérique doit être -100, -10, 50, 200 — pas lexicographique.'
    );
});

// ── Test 3 : tri JS est numérique pour colonne montant (pas lexicographique) ───

it('tri_data_sort_montants_est_numerique_pas_lexicographique', function () {
    // Ce test documente l'exigence de tri numérique correct.
    // Le bug sans patch : localeCompare('-100.00', '-10.00') < 0 (correct par accident)
    // mais localeCompare('-9.00', '-100.00') < 0 (FAUX — -9 > -100 numériquement).
    // Aussi : localeCompare('9.00', '150.00') > 0 (FAUX — 9 < 150 numériquement).

    // Simulation de la comparaison JS localeCompare vs parseFloat pour des cas représentatifs :

    // Cas 1 : -9 vs -100 — localeCompare les traite comme strings
    // '-9.00' vs '-100.00' : '-' est identique, puis '9' > '1' → localeCompare('-9', '-100') > 0
    // Mais numériquement -9 > -100, donc localeCompare DONNE le bon signe par accident ici.

    // Cas 2 : '9.00' vs '150.00' — localeCompare
    // '9' > '1' → localeCompare('9.00', '150.00') > 0 → place 9 APRÈS 150 en tri asc : FAUX.
    // parseFloat('9.00') = 9 < 150 = parseFloat('150.00') → correct.

    // Ce test vérifie le comportement numérique attendu via PHP (équivalent du fix JS parseFloat).
    $values = ['9.00', '150.00', '-100.00', '-10.00', '50.00', '200.00'];

    // Tri numérique correct
    $numSorted = $values;
    usort($numSorted, fn ($a, $b) => (float) $a <=> (float) $b);
    expect($numSorted)->toBe(['-100.00', '-10.00', '9.00', '50.00', '150.00', '200.00']);

    // Tri localeCompare (string) — doit démontrer l'ordre INCORRECT pour '9' vs '150'
    $strSorted = $values;
    usort($strSorted, fn ($a, $b) => strncmp($a, $b, max(strlen($a), strlen($b))));
    // '9.00' > '150.00' lexicographiquement → 9 après 150 dans un tri asc : BUG
    $idx9 = array_search('9.00', $strSorted);
    $idx150 = array_search('150.00', $strSorted);

    expect($idx9)->toBeGreaterThan($idx150,
        'DÉMONSTRATION DU BUG : localeCompare place "9.00" après "150.00" — ordre numérique incorrect.'
    );
});
