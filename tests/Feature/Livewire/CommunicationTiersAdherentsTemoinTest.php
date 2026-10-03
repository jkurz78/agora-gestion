<?php

declare(strict_types=1);

use App\Livewire\CommunicationTiers;
use App\Models\Adhesion;
use App\Models\Association;
use App\Models\Compte;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Models\User;
use App\Tenant\TenantContext;
use Livewire\Livewire;

/*
 * TÉMOIN DE NON-RÉGRESSION (spec 2026-10-02, AC-10).
 *
 * Le filtre « Adhérents / Exercice en cours » de la communication est le chemin
 * recommandé pour une convocation d'assemblée générale, et il donne aujourd'hui la
 * bonne liste. Il ne lit PAS la table adhesions : il interroge les transactions
 * (une recette, dans l'exercice visé par sa date, dont une ligne pointe un compte
 * d'usage « cotisation »).
 *
 * Ce test fige son comportement. Il doit rester vert, à l'identique, avant et
 * après le correctif de complétude de l'exercice — et après toute évolution de
 * adhesions.exercice. S'il tombe, le correctif a touché ce qui marchait.
 */

beforeEach(function (): void {
    $this->association = Association::factory()->create(['email_from' => 'test@asso.fr']);
    $this->admin = User::factory()->create();
    $this->admin->associations()->attach($this->association->id, ['role' => 'admin', 'joined_at' => now()]);
    TenantContext::boot($this->association);
    session(['current_association_id' => $this->association->id, 'exercice_actif' => 2025]);
    $this->actingAs($this->admin);

    $this->cotisation = Compte::factory()->pourCotisations()->create(['association_id' => $this->association->id]);
    $this->autreCompte = Compte::factory()->numero('754')->pourDons()->create(['association_id' => $this->association->id]);
});

afterEach(function (): void {
    TenantContext::clear();
});

function temoinTiers(string $nom): Tiers
{
    return Tiers::factory()->create(['association_id' => test()->association->id, 'nom' => $nom, 'email' => strtolower($nom).'@e.com']);
}

function temoinReglement(Tiers $tiers, Compte $compte, string $date, string $type = 'recette'): Transaction
{
    $tx = Transaction::factory()->create([
        'association_id' => test()->association->id,
        'tiers_id' => $tiers->id,
        'type' => $type,
        'date' => $date,
    ]);
    TransactionLigne::withoutEvents(fn () => TransactionLigne::factory()->create([
        'transaction_id' => $tx->id,
        'compte_id' => $compte->id,
        'montant' => 30,
        'credit' => 30,
    ]));

    return $tx;
}

/** @return list<string> */
function temoinFiltre(string $portee): array
{
    $noms = Livewire::test(CommunicationTiers::class)
        ->set('filtreAdherents', $portee)
        ->viewData('tiersList')
        ->pluck('nom')
        ->map(fn (string $nom): string => mb_strtoupper($nom))
        ->all();
    sort($noms);

    return $noms;
}

it('AC-10 · le filtre « exercice en cours » rend exactement les tiers ayant réglé une cotisation sur l\'exercice 2025-2026', function (): void {
    // Ont réglé en 2025-2026 : trois dates, dont les deux bornes de l'exercice.
    $debut = temoinTiers('Debut');
    temoinReglement($debut, $this->cotisation, '2025-09-01');
    $milieu = temoinTiers('Milieu');
    temoinReglement($milieu, $this->cotisation, '2026-02-01');
    $fin = temoinTiers('Fin');
    temoinReglement($fin, $this->cotisation, '2026-08-31');

    // L'état de la table adhesions n'y change rien : exercice nul, renseigné, ou absent.
    Adhesion::factory()->create(['association_id' => $this->association->id, 'tiers_id' => $debut->id, 'exercice' => null, 'date_debut' => '2025-09-01', 'date_fin' => '2026-08-31']);
    Adhesion::factory()->create(['association_id' => $this->association->id, 'tiers_id' => $milieu->id, 'exercice' => 2025]);

    // Saison suivante seulement (le cas Kohl / Salin Beneteau).
    $suivant = temoinTiers('Suivant');
    temoinReglement($suivant, $this->cotisation, '2026-09-05');
    Adhesion::factory()->create(['association_id' => $this->association->id, 'tiers_id' => $suivant->id, 'exercice' => null, 'date_debut' => '2026-09-01', 'date_fin' => '2027-08-31']);

    // Adhésion sans aucun règlement (offerte) : le filtre ne la voit pas, c'est connu et hors scope.
    $offerte = temoinTiers('Offerte');
    Adhesion::factory()->create(['association_id' => $this->association->id, 'tiers_id' => $offerte->id, 'exercice' => 2025]);

    // Règlement sur un autre compte, ou dépense sur le compte de cotisation : pas des adhérents.
    $donateur = temoinTiers('Donateur');
    temoinReglement($donateur, $this->autreCompte, '2025-10-15');
    $fournisseur = temoinTiers('Fournisseur');
    temoinReglement($fournisseur, $this->cotisation, '2025-10-15', 'depense');

    temoinTiers('Inconnu');

    expect(temoinFiltre('exercice'))->toBe(['DEBUT', 'FIN', 'MILIEU'])
        ->and(temoinFiltre('tous'))->toBe(['DEBUT', 'FIN', 'MILIEU', 'SUIVANT']);
});
