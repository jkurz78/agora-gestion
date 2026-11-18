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
 * Une adhésion offerte (gratuite, ou saisie à 0 €) n'a pas de transaction : le filtre
 * « Adhérents » de la communication, qui lit les règlements de cotisation, ne la
 * voyait pas. Conséquence : un membre dont l'adhésion a été offerte n'était pas
 * convoqué à l'assemblée générale.
 *
 * Pour une adhésion offerte, l'exercice retenu est sa saison (adhesions.exercice) :
 * il n'y a pas de date de règlement. Les adhésions payées restent jugées sur la date
 * de leur règlement (témoin AC-10).
 */

beforeEach(function (): void {
    $this->association = Association::factory()->create(['email_from' => 'test@asso.fr']);
    $this->admin = User::factory()->create();
    $this->admin->associations()->attach($this->association->id, ['role' => 'admin', 'joined_at' => now()]);
    TenantContext::boot($this->association);
    session(['current_association_id' => $this->association->id, 'exercice_actif' => 2025]);
    $this->actingAs($this->admin);

    $this->cotisation = Compte::factory()->pourCotisations()->create(['association_id' => $this->association->id]);
});

afterEach(function (): void {
    TenantContext::clear();
});

function offertTiers(string $nom): Tiers
{
    return Tiers::factory()->create(['association_id' => test()->association->id, 'nom' => $nom, 'email' => strtolower($nom).'@e.com']);
}

function offertAdhesion(Tiers $tiers, ?int $exercice, array $attributs = []): Adhesion
{
    return Adhesion::factory()->create(array_merge([
        'association_id' => test()->association->id,
        'tiers_id' => $tiers->id,
        'exercice' => $exercice,
        'transaction_id' => null,
    ], $attributs));
}

function offertReglement(Tiers $tiers, string $date): Transaction
{
    $tx = Transaction::factory()->create([
        'association_id' => test()->association->id,
        'tiers_id' => $tiers->id,
        'type' => 'recette',
        'date' => $date,
    ]);
    TransactionLigne::withoutEvents(fn () => TransactionLigne::factory()->create([
        'transaction_id' => $tx->id,
        'compte_id' => test()->cotisation->id,
        'montant' => 30,
        'credit' => 30,
    ]));

    return $tx;
}

/** @return list<string> */
function offertFiltre(string $portee, string $search = ''): array
{
    $noms = Livewire::test(CommunicationTiers::class)
        ->set('search', $search)
        ->set('filtreAdherents', $portee)
        ->viewData('tiersList')
        ->pluck('nom')
        ->map(fn (string $nom): string => mb_strtoupper($nom))
        ->all();
    sort($noms);

    return $noms;
}

it('retient une adhésion offerte sur l\'exercice de sa saison, et sur « tous exercices »', function (): void {
    // Le cas du président : offerte du 01/09/2025 au 31/08/2026, saisie après coup.
    offertAdhesion(offertTiers('President'), 2025, [
        'mode' => 'duree',
        'date_debut' => '2025-09-01',
        'date_fin' => '2026-08-31',
    ]);
    offertAdhesion(offertTiers('Ancien'), 2024);

    expect(offertFiltre('exercice'))->toBe(['PRESIDENT'])
        ->and(offertFiltre('tous'))->toBe(['ANCIEN', 'PRESIDENT']);
});

it('ignore une adhésion offerte supprimée', function (): void {
    offertAdhesion(offertTiers('Supprimee'), 2025)->delete();

    expect(offertFiltre('exercice'))->toBe([])
        ->and(offertFiltre('tous'))->toBe([]);
});

it('juge toujours une adhésion payée sur la date de son règlement, pas sur sa saison', function (): void {
    // Réglée le 20/08/2025 (exercice 2024-2025) pour la saison 2025-2026.
    $anticipe = offertTiers('Anticipe');
    $tx = offertReglement($anticipe, '2025-08-20');
    offertAdhesion($anticipe, 2025, ['transaction_id' => $tx->id, 'montant_facial' => 30]);

    expect(offertFiltre('exercice'))->toBe([])
        ->and(offertFiltre('tous'))->toBe(['ANTICIPE']);
});

it('reste combiné en ET avec la recherche', function (): void {
    offertAdhesion(offertTiers('Offerte'), 2025);
    offertReglement(offertTiers('Payeur'), '2025-10-01');

    expect(offertFiltre('exercice', 'Payeur'))->toBe(['PAYEUR'])
        ->and(offertFiltre('exercice', 'Offerte'))->toBe(['OFFERTE']);
});
