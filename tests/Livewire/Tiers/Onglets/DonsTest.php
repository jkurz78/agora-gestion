<?php

declare(strict_types=1);

use App\Enums\StatutReglement;
use App\Enums\TypeTransaction;
use App\Enums\UsageComptable;
use App\Livewire\Tiers\Onglets\Dons;
use App\Models\Association;
use App\Models\RecuFiscalEmis;
use App\Models\SousCategorie;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Models\User;
use App\Tenant\TenantContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());

    $asso = Association::find(TenantContext::currentId());
    $asso->update([
        'eligible_recu_fiscal' => true,
        'signataire_nom' => 'Marie Curie',
        'signataire_qualite' => 'Présidente',
    ]);
    TenantContext::boot($asso->fresh());

    $this->sousCat = SousCategorie::factory()->create(['nom' => 'Don courant']);
    $this->sousCat->usages()->create(['usage' => UsageComptable::Don->value]);
});

function makeDonForDonsTest(Tiers $tiers, SousCategorie $sousCat, string $date, float $montant, array $tx = []): TransactionLigne
{
    $tx = Transaction::factory()->create(array_merge([
        'tiers_id' => $tiers->id,
        'date' => $date,
        'type' => TypeTransaction::Recette->value,
        'statut_reglement' => StatutReglement::Recu->value,
    ], $tx));

    return TransactionLigne::factory()->create([
        'transaction_id' => $tx->id,
        'sous_categorie_id' => $sousCat->id,
        'montant' => $montant,
    ]);
}

it('groupe les dons par année civile en ordre desc', function (): void {
    $tiers = Tiers::factory()->create([
        'adresse_ligne1' => '1 rue', 'code_postal' => '69000', 'ville' => 'Lyon',
    ]);
    makeDonForDonsTest($tiers, $this->sousCat, '2024-06-15', 100);
    makeDonForDonsTest($tiers, $this->sousCat, '2025-03-10', 50);

    $component = Livewire::test(Dons::class, ['tiers' => $tiers]);

    $html = $component->html();
    expect(strpos($html, '2025') < strpos($html, '2024'))->toBeTrue();
});

it('affiche un badge "Reçu émis" si un reçu actif existe', function (): void {
    $tiers = Tiers::factory()->create([
        'adresse_ligne1' => '1 rue', 'code_postal' => '69000', 'ville' => 'Lyon',
    ]);
    $don = makeDonForDonsTest($tiers, $this->sousCat, '2025-03-10', 50);
    RecuFiscalEmis::factory()->create([
        'transaction_ligne_id' => $don->id,
        'annule_at' => null,
    ]);

    Livewire::test(Dons::class, ['tiers' => $tiers])
        ->assertSee('Reçu émis');
});

it('désactive le bouton télécharger si l\'adresse du tiers est incomplète', function (): void {
    $tiers = Tiers::factory()->create([
        'adresse_ligne1' => null, 'code_postal' => '69000', 'ville' => 'Lyon',
    ]);
    makeDonForDonsTest($tiers, $this->sousCat, '2025-03-10', 50);

    Livewire::test(Dons::class, ['tiers' => $tiers])
        ->assertSee('Adresse du donateur incomplète');
});

it('affiche un encart de blocage global si signataire absent', function (): void {
    $asso = Association::find(TenantContext::currentId());
    $asso->update(['signataire_nom' => null, 'signataire_qualite' => null]);
    TenantContext::boot($asso->fresh());

    $tiers = Tiers::factory()->create();
    makeDonForDonsTest($tiers, $this->sousCat, '2025-03-10', 50);

    Livewire::test(Dons::class, ['tiers' => $tiers])
        ->assertSee('signataire');
});

// ── Sécurité : gardes tenant + tiers ─────────────────────────────────────────

it("refuse d'afficher les avertissements d'un don d'un autre tenant", function (): void {
    $tiersA = Tiers::factory()->create();

    $assoB = Association::factory()->create();
    TenantContext::boot($assoB);
    $sousCatB = SousCategorie::factory()->create(['nom' => 'Don B', 'association_id' => $assoB->id]);
    $sousCatB->usages()->create(['usage' => UsageComptable::Don->value]);
    $tiersB = Tiers::factory()->create(['association_id' => $assoB->id]);
    $donB = makeDonForDonsTest($tiersB, $sousCatB, '2025-04-01', 80);

    TenantContext::boot(Association::find($tiersA->association_id));

    // La ligne du tenant B est invisible sous le scope tenant de TransactionLigne
    // (audit #8) : findOrFail lève ModelNotFound (→ 404 en HTTP), avant même le
    // garde 403. Blocage cross-tenant plus fort (la ressource « n'existe pas » pour A).
    expect(fn () => Livewire::test(Dons::class, ['tiers' => $tiersA])
        ->call('afficherAvertissements', $donB->id))
        ->toThrow(ModelNotFoundException::class);
});

it("refuse d'afficher les avertissements d'un don d'un autre tiers (même tenant)", function (): void {
    $tiers = Tiers::factory()->create();
    $autreTiers = Tiers::factory()->create();
    $donAutreTiers = makeDonForDonsTest($autreTiers, $this->sousCat, '2025-04-01', 80);

    Livewire::test(Dons::class, ['tiers' => $tiers])
        ->call('afficherAvertissements', $donAutreTiers->id)
        ->assertStatus(403);
});

it("refuse de re-émettre un reçu d'un autre tiers (même tenant)", function (): void {
    $tiers = Tiers::factory()->create();
    $autreTiers = Tiers::factory()->create();
    $donAutreTiers = makeDonForDonsTest($autreTiers, $this->sousCat, '2025-04-01', 80);
    $recuAutreTiers = RecuFiscalEmis::factory()->create([
        'transaction_ligne_id' => $donAutreTiers->id,
        'tiers_id' => $autreTiers->id,
        'annule_at' => null,
    ]);

    Livewire::test(Dons::class, ['tiers' => $tiers])
        ->set('recuAAnnuler', $recuAutreTiers->id)
        ->set('motifAnnulation', 'tentative cross-tiers')
        ->call('confirmerReEmission')
        ->assertStatus(403);
});
